<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\Application\PurchaseRefundReferralOutboxHandler;
use App\Modules\Promotions\Application\PurchaseSettlementReferralOutboxHandler;
use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Modules\Promotions\Application\ReferralRewardMaintenanceService;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Modules\Promotions\Domain\ReferralRewardRecipient;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReferralRewardRuntimeWiringClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement REF-001 WAL-002 ARCH-004 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
final class ReferralRewardRuntimeWiringTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use ReferralRewardLifecycleTestSupport;
    use RefreshDatabase;

    private ReferralRewardRuntimeWiringClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);

        $this->clock = new ReferralRewardRuntimeWiringClock(
            new DateTimeImmutable('2026-08-14T06:00:00+00:00'),
        );
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_settlement_refund_handlers_and_maintenance_apply_referral_effects_exactly_once(): void
    {
        [$inviter, $referred] = $this->referralRelationshipAndRule('runtime', 1);
        [$settlement, $providerCode] = $this->captureLifecyclePurchase($referred, 'runtime');

        $settlementMessage = $this->outboxMessage(
            'purchase.referral_reward.accrual_requested',
            $settlement->settlementPublicId,
        );
        $settlementHandler = $this->app->make(PurchaseSettlementReferralOutboxHandler::class);
        self::assertSame(OutboxDispatchOutcome::Success, $settlementHandler->handle($settlementMessage));
        self::assertSame(1, DB::table('referral_reward_accruals')
            ->where('purchase_settlement_public_id', $settlement->settlementPublicId)
            ->count());
        self::assertSame(1, DB::table('referral_rewards')
            ->where('recipient_user_id', $inviter)
            ->where('state', 'pending')
            ->count());

        self::assertSame(OutboxDispatchOutcome::Success, $settlementHandler->handle($settlementMessage));
        self::assertSame(1, DB::table('referral_reward_accruals')
            ->where('purchase_settlement_public_id', $settlement->settlementPublicId)
            ->count());
        self::assertSame(1, DB::table('referral_rewards')->where('recipient_user_id', $inviter)->count());

        $this->clock->value = $this->clock->value->modify('+2 hours');
        $maintenance = $this->app->make(ReferralRewardMaintenanceService::class);
        self::assertSame(['examined' => 1, 'changed' => 1], $maintenance->process(100));
        self::assertSame('released', DB::table('referral_rewards')
            ->where('recipient_user_id', $inviter)
            ->value('state'));
        self::assertSame(1, DB::table('ledger_transactions')
            ->where('transaction_type', 'referral_reward_release')
            ->count());
        self::assertSame(['examined' => 0, 'changed' => 0], $maintenance->process(100));

        $refund = $this->recordLifecycleRefund($settlement, $providerCode, 'runtime');
        $refundMessage = $this->outboxMessage(
            'purchase.referral_reward.refund_requested',
            $refund->publicId,
        );
        $refundHandler = $this->app->make(PurchaseRefundReferralOutboxHandler::class);
        self::assertSame(OutboxDispatchOutcome::Success, $refundHandler->handle($refundMessage));
        self::assertSame('reversed', DB::table('referral_rewards')
            ->where('recipient_user_id', $inviter)
            ->value('state'));
        self::assertSame(1, DB::table('ledger_transactions')
            ->where('transaction_type', 'referral_reward_reversal')
            ->count());

        self::assertSame(OutboxDispatchOutcome::Success, $refundHandler->handle($refundMessage));
        self::assertSame(1, DB::table('ledger_transactions')
            ->where('transaction_type', 'referral_reward_reversal')
            ->count());
    }

    /** @return array{0:int,1:int} */
    private function referralRelationshipAndRule(string $suffix, int $pendingHours): array
    {
        $inviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $attribution = $this->app->make(ReferralAttributionService::class);
        $token = $attribution->identityForUser($inviter);
        $attribution->bind($referred, $token);

        $administratorId = $this->ownerAdministrator();
        $this->app->make(PromotionRuleService::class)->create(
            'referral.runtime.rule.'.$suffix,
            'referral-runtime-'.$suffix,
            PromotionRuleKind::Referral,
            new PromotionRuleDefinition(
                PromotionRuleState::Active,
                100,
                PromotionDiscountType::Fixed,
                100_000,
                null,
                0,
                null,
                null,
                null,
                null,
                null,
                false,
                PromotionAudience::Customers,
                null,
                null,
                null,
                null,
                null,
                PromotionAction::Purchase,
                $token,
                false,
                ReferralRewardRecipient::Inviter,
                $pendingHours,
                null,
                false,
                null,
            ),
            new AccessChangeContext(
                hash('sha256', 'referral-runtime-rule-request:'.$suffix),
                $this->lifecycleCorrelation('runtime-rule-'.$suffix),
                'referral_runtime_wiring_test',
                'Referral runtime wiring test rule.',
                $administratorId,
            ),
        );

        return [$inviter, $referred];
    }

    private function outboxMessage(string $eventType, string $aggregateId): OutboxMessage
    {
        $row = DB::table('outbox_messages')
            ->where('event_type', $eventType)
            ->where('aggregate_id', $aggregateId)
            ->first([
                'id',
                'event_key',
                'event_type',
                'aggregate_type',
                'aggregate_id',
                'payload',
                'correlation_id',
                'contract_version',
            ]);
        self::assertNotNull($row);
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return new OutboxMessage(
            (string) $row->id,
            (string) $row->event_key,
            (string) $row->event_type,
            (string) $row->aggregate_type,
            (string) $row->aggregate_id,
            $payload,
            (string) $row->correlation_id,
            1,
            (int) $row->contract_version,
        );
    }
}
