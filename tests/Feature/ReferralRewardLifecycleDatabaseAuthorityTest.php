<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\Application\ReferralRewardLifecycleService;
use App\Modules\Promotions\Domain\ReferralRewardState;
use App\Modules\Wallet\Application\ReferralRewardWalletService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReferralRewardLifecycleDatabaseClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-004 */
final class ReferralRewardLifecycleDatabaseAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use ReferralRewardLifecycleTestSupport;
    use RefreshDatabase;

    private ReferralRewardLifecycleDatabaseClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new ReferralRewardLifecycleDatabaseClock(new DateTimeImmutable('2026-08-14T07:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_refunded_purchase_cannot_commit_release_event_even_with_valid_balanced_ledger_effect(): void
    {
        $fixture = $this->pendingReferralRewardFixture('database-refund-guard', 1);
        $this->createLifecyclePromotionalWallet($fixture['recipient_user_id'], 'database-refund-guard');
        $refund = $this->recordLifecycleRefund(
            $fixture['settlement'],
            $fixture['provider_code'],
            'database-refund-guard',
        );
        $this->clock->value = $this->clock->value->modify('+2 hours');
        $reward = DB::table('referral_rewards')->where('public_id', $fixture['reward_public_id'])->first();
        self::assertNotNull($reward);

        try {
            DB::transaction(function () use ($fixture, $reward): void {
                $ledger = $this->app->make(ReferralRewardWalletService::class)->release(
                    'referral.reward.database.refund.guard',
                    $fixture['recipient_user_id'],
                    $fixture['reward_public_id'],
                    IrrMoney::positive((int) $reward->amount_irr),
                    $this->lifecycleCorrelation('database-refund-ledger'),
                );

                DB::table('referral_reward_lifecycle_events')->insert([
                    'referral_reward_id' => (int) $reward->id,
                    'event_type' => 'released',
                    'ledger_transaction_id' => $ledger->transactionId,
                    'purchase_refund_id' => null,
                    'occurred_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
                    'correlation_id' => $this->lifecycleCorrelation('database-refund-event'),
                    'created_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
                ]);
            });
            self::fail('Expected refunded purchase release authority to be rejected.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_release')->count());
            self::assertSame(0, DB::table('referral_reward_lifecycle_events')->where('event_type', 'released')->count());
            self::assertSame('pending', DB::table('referral_rewards')->where('id', $reward->id)->value('state'));
        }

        $receipts = $this->app->make(ReferralRewardLifecycleService::class)->applyPurchaseRefund(
            $refund->publicId,
            $this->lifecycleCorrelation('database-refund-cancel'),
        );
        self::assertCount(1, $receipts);
        self::assertSame(ReferralRewardState::Canceled, $receipts[0]->state);
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_release')->count());
    }
}
