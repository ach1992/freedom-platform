<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Modules\Promotions\Application\ReferralRewardAccrualService;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Modules\Promotions\Domain\ReferralRewardRecipient;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReferralRewardAccrualClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement REF-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class ReferralRewardAccrualTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private ReferralRewardAccrualClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new ReferralRewardAccrualClock(new DateTimeImmutable('2026-08-14T02:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_fixed_inviter_reward_defaults_to_24_hours_and_replays_exactly_once(): void
    {
        $inviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $attribution = $this->app->make(ReferralAttributionService::class);
        $token = $attribution->identityForUser($inviter);
        $attribution->bind($referred, $token);
        $this->createRewardRule('fixed', $token, PromotionDiscountType::Fixed, 100_000, null, 100_000, true, ReferralRewardRecipient::Inviter, null, null, false, 1, 1, 1);

        $settlement = $this->capturePurchaseFor($referred, 'fixed-first');
        $service = $this->app->make(ReferralRewardAccrualService::class);
        $created = $service->accrue($settlement->settlementPublicId, $this->correlation('accrue-fixed'));

        self::assertNotNull($created);
        self::assertFalse($created->replayed);
        self::assertSame(100_000, $created->rewardAmountIrr);
        self::assertSame('2026-08-15 02:00:00.000000', $created->releaseAt->format('Y-m-d H:i:s.u'));
        self::assertNull($created->expiresAt);
        self::assertCount(1, $created->rewardPublicIds);
        self::assertSame(1, DB::table('referral_reward_accruals')->count());
        self::assertSame(1, DB::table('referral_rewards')->count());
        self::assertSame($inviter, (int) DB::table('referral_rewards')->value('recipient_user_id'));
        self::assertSame('pending', DB::table('referral_rewards')->value('state'));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.pending')->count());

        $replay = $service->accrue($settlement->settlementPublicId, $this->correlation('accrue-fixed-replay'));
        self::assertNotNull($replay);
        self::assertTrue($replay->replayed);
        self::assertSame($created->accrualId, $replay->accrualId);
        self::assertSame($created->rewardPublicIds, $replay->rewardPublicIds);
        self::assertSame(1, DB::table('referral_reward_accruals')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.pending')->count());

        $secondSettlement = $this->capturePurchaseFor($referred, 'fixed-second');
        self::assertNull($service->accrue($secondSettlement->settlementPublicId, $this->correlation('accrue-fixed-second')));
        self::assertSame(1, DB::table('referral_reward_accruals')->count());
    }

    public function test_percentage_both_reward_respects_cap_expiry_transferability_and_immutable_history(): void
    {
        $inviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $attribution = $this->app->make(ReferralAttributionService::class);
        $token = $attribution->identityForUser($inviter);
        $attribution->bind($referred, $token);
        $this->createRewardRule('percentage', $token, PromotionDiscountType::Percentage, null, 2500, 120_000, false, ReferralRewardRecipient::Both, 48, 72, true, 3, 3, 3);

        $settlement = $this->capturePurchaseFor($referred, 'percentage');
        $created = $this->app->make(ReferralRewardAccrualService::class)->accrue($settlement->settlementPublicId, $this->correlation('accrue-percentage'));

        self::assertNotNull($created);
        self::assertSame(120_000, $created->rewardAmountIrr);
        self::assertSame('2026-08-16 02:00:00.000000', $created->releaseAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-08-19 02:00:00.000000', $created->expiresAt?->format('Y-m-d H:i:s.u'));
        self::assertCount(2, $created->rewardPublicIds);
        self::assertSame(['inviter', 'referred'], DB::table('referral_rewards')->orderBy('recipient_role')->pluck('recipient_role')->all());
        self::assertSame([120_000, 120_000], DB::table('referral_rewards')->orderBy('recipient_role')->pluck('amount_irr')->map(static fn (mixed $value): int => (int) $value)->all());
        self::assertSame([1, 1], DB::table('referral_rewards')->orderBy('recipient_role')->pluck('transferable')->map(static fn (mixed $value): int => (int) $value)->all());
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'referral.reward.pending')->count());

        foreach (DB::table('outbox_messages')->where('event_type', 'referral.reward.pending')->pluck('payload')->all() as $payload) {
            $json = (string) $payload;
            self::assertStringNotContainsString($token, $json);
            self::assertStringNotContainsString('lookup_hash', $json);
            self::assertStringContainsString('reward_public_id', $json);
        }

        $this->assertQueryRejected(static fn (): int => DB::table('referral_reward_accruals')->where('id', $created->accrualId)->update(['reward_amount_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('referral_rewards')->where('public_id', $created->rewardPublicIds[0])->update(['amount_irr' => 1]));
    }

    public function test_shared_verified_phone_denies_reward_without_merging_accounts(): void
    {
        $inviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $attribution = $this->app->make(ReferralAttributionService::class);
        $token = $attribution->identityForUser($inviter);
        $attribution->bind($referred, $token);
        $this->createRewardRule('shared-phone', $token, PromotionDiscountType::Fixed, 100_000, null, null, false, ReferralRewardRecipient::Inviter, null, null, false, null, null, null);

        $sharedHash = hash('sha256', 'shared-verified-phone');
        foreach ([$inviter, $referred] as $index => $userId) {
            DB::table('phone_numbers')->insert([
                'user_id' => $userId,
                'active_user_id' => null,
                'encrypted_value' => 'ciphertext-'.$index,
                'lookup_hash' => $sharedHash,
                'active_lookup_hash' => null,
                'hash_key_version' => 1,
                'status' => 'verified',
                'verification_policy' => 'otp',
                'verification_policy_version' => 1,
                'last_verification_method' => 'otp',
                'verified_via_telegram_account_id' => null,
                'verified_at' => $this->timestamp(),
                'released_at' => $this->timestamp(),
                'created_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);
        }

        $settlement = $this->capturePurchaseFor($referred, 'shared-phone');
        self::assertNull($this->app->make(ReferralRewardAccrualService::class)->accrue($settlement->settlementPublicId, $this->correlation('accrue-shared-phone')));
        self::assertSame(0, DB::table('referral_reward_accruals')->count());
        self::assertSame(0, DB::table('referral_rewards')->count());
        self::assertSame(2, DB::table('users')->whereIn('id', [$inviter, $referred])->count());
    }

    public function test_database_rejects_forged_recipient_outside_immutable_policy(): void
    {
        $inviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $attribution = $this->app->make(ReferralAttributionService::class);
        $token = $attribution->identityForUser($inviter);
        $attribution->bind($referred, $token);
        $this->createRewardRule('forged-recipient', $token, PromotionDiscountType::Fixed, 100_000, null, null, false, ReferralRewardRecipient::Inviter, null, null, false, null, null, null);

        $settlement = $this->capturePurchaseFor($referred, 'forged-recipient');
        $created = $this->app->make(ReferralRewardAccrualService::class)->accrue($settlement->settlementPublicId, $this->correlation('accrue-forged-recipient'));
        self::assertNotNull($created);

        $this->assertQueryRejected(fn (): bool => DB::table('referral_rewards')->insert([
            'public_id' => (string) Str::ulid(),
            'accrual_id' => $created->accrualId,
            'purchase_settlement_id' => $settlement->settlementId,
            'recipient_role' => 'referred',
            'recipient_user_id' => $referred,
            'amount_irr' => $created->rewardAmountIrr,
            'state' => 'pending',
            'release_at' => $created->releaseAt->format('Y-m-d H:i:s.u'),
            'expires_at' => null,
            'transferable' => false,
            'created_at' => $this->timestamp(),
        ]));
        self::assertSame(1, DB::table('referral_rewards')->count());
    }

    private function createRewardRule(string $suffix, string $referralSourceCode, PromotionDiscountType $discountType, ?int $fixedRewardIrr, ?int $basisPoints, ?int $maximumRewardIrr, bool $firstPurchaseOnly, ReferralRewardRecipient $recipient, ?int $pendingHours, ?int $expiryHours, bool $transferable, ?int $totalUseLimit, ?int $perUserUseLimit, ?int $perReferralUseLimit): void
    {
        $administratorId = $this->ownerAdministrator();
        $this->app->make(PromotionRuleService::class)->create(
            'referral.reward.rule.'.$suffix,
            'referral-reward-'.$suffix,
            PromotionRuleKind::Referral,
            new PromotionRuleDefinition(PromotionRuleState::Active, 100, $discountType, $fixedRewardIrr, $basisPoints, 0, $maximumRewardIrr, null, null, $totalUseLimit, $perUserUseLimit, $firstPurchaseOnly, PromotionAudience::Customers, null, null, null, null, null, PromotionAction::Purchase, $referralSourceCode, false, $recipient, $pendingHours, $expiryHours, $transferable, $perReferralUseLimit),
            new AccessChangeContext(hash('sha256', 'referral-reward-rule-request:'.$suffix), $this->correlation('rule-'.$suffix), 'referral_reward_test', 'Referral reward test configuration.', $administratorId),
        );
    }

    private function capturePurchaseFor(int $userId, string $suffix): PurchaseSettlementReceipt
    {
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'referral.reward.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $methodCode = 'referral_reward_gateway_'.$suffix;
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod('referral.reward.method.'.$suffix, $administratorId, $methodCode, true, false, 1, 'Referral reward settlement test configuration.', $this->correlation('method-'.$suffix));
        $eligibility->recordHealth('referral.reward.health.'.$suffix, $administratorId, $methodCode, true, $this->clock->value->modify('+10 minutes'), 'Healthy referral reward test observation.', $this->correlation('health-'.$suffix));
        $decision = $eligibility->evaluate('referral.reward.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        $purchase = $this->app->make(PurchasePaymentIntentService::class)->create('referral.reward.intent.'.$suffix, $userId, $quote->quotePublicId, $decision->publicId, $methodCode, $this->correlation('intent-'.$suffix));
        DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->update(['state' => 'submitted', 'updated_at' => $this->timestamp()]);

        return $this->app->make(PurchaseSettlementService::class)->capture(
            $purchase->intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                'referral-reward-event-'.$suffix,
                hash('sha256', 'referral-reward-provider-event:'.$suffix),
                new PaymentEvidence(ProviderOperationOutcome::Success, PaymentEvidenceAuthority::Authoritative, PaymentTransactionStatus::Settled, 'referral-reward-transaction-'.$suffix, 'referral-reward-event-'.$suffix, Money::irr($purchase->amount->amount()), $this->clock->value, $this->clock->value, hash('sha256', 'referral-reward-provider-evidence:'.$suffix), ['provider_reference' => 'referral-reward-transaction-'.$suffix]),
            ),
            $this->correlation('capture-'.$suffix),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'referral-reward:'.$suffix);
    }

    private function timestamp(): string
    {
        return $this->clock->value->format('Y-m-d H:i:s.u');
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
