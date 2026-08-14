<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\Application\ReferralRewardLifecycleService;
use App\Modules\Promotions\Domain\ReferralRewardState;
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

final class ReferralRewardLifecycleClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
final class ReferralRewardLifecycleTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use ReferralRewardLifecycleTestSupport;
    use RefreshDatabase;

    private ReferralRewardLifecycleClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new ReferralRewardLifecycleClock(new DateTimeImmutable('2026-08-14T06:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_mature_reward_releases_once_through_balanced_promotional_wallet_effect(): void
    {
        $fixture = $this->pendingReferralRewardFixture('release');
        $service = $this->app->make(ReferralRewardLifecycleService::class);

        $early = $service->process($fixture['reward_public_id'], $this->lifecycleCorrelation('release-early'));
        self::assertSame(ReferralRewardState::Pending, $early->state);
        self::assertFalse($early->changed);
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_release')->count());
        self::assertSame(0, DB::table('ledger_accounts')->where('owner_user_id', $fixture['recipient_user_id'])->where('wallet_bucket', 'promotional')->count());

        $this->clock->value = $this->clock->value->modify('+2 hours');
        $released = $service->process($fixture['reward_public_id'], $this->lifecycleCorrelation('release-due'));
        self::assertSame(ReferralRewardState::Released, $released->state);
        self::assertTrue($released->changed);
        self::assertNotNull($released->releaseLedgerTransactionId);
        self::assertNull($released->reversalLedgerTransactionId);
        self::assertNull($released->purchaseRefundId);
        $walletId = (int) DB::table('ledger_accounts')
            ->where('owner_user_id', $fixture['recipient_user_id'])
            ->where('wallet_bucket', 'promotional')
            ->value('id');
        self::assertGreaterThan(0, $walletId);

        $replay = $service->process($fixture['reward_public_id'], $this->lifecycleCorrelation('release-replay'));
        self::assertSame(ReferralRewardState::Released, $replay->state);
        self::assertTrue($replay->replayed);
        self::assertSame($released->releaseLedgerTransactionId, $replay->releaseLedgerTransactionId);
        self::assertSame(1, DB::table('ledger_accounts')->where('owner_user_id', $fixture['recipient_user_id'])->where('wallet_bucket', 'promotional')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_release')->count());
        self::assertSame(1, DB::table('referral_reward_lifecycle_events')->where('event_type', 'released')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.released')->count());

        $transaction = DB::table('ledger_transactions')->where('id', $released->releaseLedgerTransactionId)->first();
        self::assertNotNull($transaction);
        self::assertSame(100_000, (int) $transaction->expected_total_irr);
        self::assertSame(100_000, (int) $transaction->posted_debit_irr);
        self::assertSame(100_000, (int) $transaction->posted_credit_irr);
        self::assertSame(2, (int) $transaction->entry_count);
        self::assertSame(
            100_000,
            (int) DB::table('ledger_entries')
                ->where('ledger_transaction_id', $released->releaseLedgerTransactionId)
                ->where('ledger_account_id', $walletId)
                ->where('direction', 'credit')
                ->value('amount_irr'),
        );
    }

    public function test_authoritative_refund_before_release_cancels_without_wallet_credit(): void
    {
        $fixture = $this->pendingReferralRewardFixture('cancel');
        $refund = $this->recordLifecycleRefund($fixture['settlement'], $fixture['provider_code'], 'cancel');

        $receipts = $this->app->make(ReferralRewardLifecycleService::class)->applyPurchaseRefund(
            $refund->publicId,
            $this->lifecycleCorrelation('cancel-apply'),
        );

        self::assertCount(1, $receipts);
        self::assertSame(ReferralRewardState::Canceled, $receipts[0]->state);
        self::assertTrue($receipts[0]->changed);
        self::assertSame($refund->refundId, $receipts[0]->purchaseRefundId);
        self::assertNull($receipts[0]->releaseLedgerTransactionId);
        self::assertNull($receipts[0]->reversalLedgerTransactionId);
        self::assertSame(0, DB::table('ledger_accounts')->where('owner_user_id', $fixture['recipient_user_id'])->where('wallet_bucket', 'promotional')->count());
        self::assertSame(0, DB::table('ledger_transactions')->whereIn('transaction_type', ['referral_reward_release', 'referral_reward_reversal'])->count());
        self::assertSame(1, DB::table('referral_reward_lifecycle_events')->where('event_type', 'canceled')->count());
        self::assertSame(0, DB::table('outbox_messages')->whereIn('event_type', ['referral.reward.released', 'referral.reward.reversed'])->count());

        $processed = $this->app->make(ReferralRewardLifecycleService::class)->process(
            $fixture['reward_public_id'],
            $this->lifecycleCorrelation('cancel-process-replay'),
        );
        self::assertSame(ReferralRewardState::Canceled, $processed->state);
        self::assertTrue($processed->replayed);
    }

    public function test_refund_after_release_creates_one_compensating_reversal_and_replays(): void
    {
        $fixture = $this->pendingReferralRewardFixture('reverse');
        $service = $this->app->make(ReferralRewardLifecycleService::class);
        $this->clock->value = $this->clock->value->modify('+2 hours');

        $released = $service->process($fixture['reward_public_id'], $this->lifecycleCorrelation('reverse-release'));
        self::assertSame(ReferralRewardState::Released, $released->state);
        $walletId = (int) DB::table('ledger_accounts')
            ->where('owner_user_id', $fixture['recipient_user_id'])
            ->where('wallet_bucket', 'promotional')
            ->value('id');
        self::assertGreaterThan(0, $walletId);
        $refund = $this->recordLifecycleRefund($fixture['settlement'], $fixture['provider_code'], 'reverse');
        $reversed = $service->applyPurchaseRefund($refund->publicId, $this->lifecycleCorrelation('reverse-apply'));

        self::assertCount(1, $reversed);
        self::assertSame(ReferralRewardState::Reversed, $reversed[0]->state);
        self::assertTrue($reversed[0]->changed);
        self::assertSame($refund->refundId, $reversed[0]->purchaseRefundId);
        self::assertSame($released->releaseLedgerTransactionId, $reversed[0]->releaseLedgerTransactionId);
        self::assertNotNull($reversed[0]->reversalLedgerTransactionId);
        self::assertNotSame($released->releaseLedgerTransactionId, $reversed[0]->reversalLedgerTransactionId);
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_release')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'referral_reward_reversal')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.released')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'referral.reward.reversed')->count());

        $walletCredits = (int) DB::table('ledger_entries')->where('ledger_account_id', $walletId)->where('direction', 'credit')->sum('amount_irr');
        $walletDebits = (int) DB::table('ledger_entries')->where('ledger_account_id', $walletId)->where('direction', 'debit')->sum('amount_irr');
        self::assertSame(100_000, $walletCredits);
        self::assertSame(100_000, $walletDebits);

        $replay = $service->applyPurchaseRefund($refund->publicId, $this->lifecycleCorrelation('reverse-replay'));
        self::assertCount(1, $replay);
        self::assertSame(ReferralRewardState::Reversed, $replay[0]->state);
        self::assertTrue($replay[0]->replayed);
        self::assertSame($reversed[0]->reversalLedgerTransactionId, $replay[0]->reversalLedgerTransactionId);
        self::assertSame(1, DB::table('ledger_accounts')->where('owner_user_id', $fixture['recipient_user_id'])->where('wallet_bucket', 'promotional')->count());
        self::assertSame(2, DB::table('ledger_transactions')->whereIn('transaction_type', ['referral_reward_release', 'referral_reward_reversal'])->count());
    }

    public function test_database_guards_reject_forged_transition_and_lifecycle_history_mutation(): void
    {
        $fixture = $this->pendingReferralRewardFixture('guards');
        $rewardId = (int) DB::table('referral_rewards')->where('public_id', $fixture['reward_public_id'])->value('id');
        $this->assertQueryRejected(static fn (): int => DB::table('referral_rewards')->where('id', $rewardId)->update([
            'state' => 'released',
            'released_at' => now('UTC'),
        ]));

        $this->clock->value = $this->clock->value->modify('+2 hours');
        $released = $this->app->make(ReferralRewardLifecycleService::class)->process(
            $fixture['reward_public_id'],
            $this->lifecycleCorrelation('guards-release'),
        );
        self::assertSame(ReferralRewardState::Released, $released->state);
        $eventId = (int) DB::table('referral_reward_lifecycle_events')->where('referral_reward_id', $rewardId)->where('event_type', 'released')->value('id');
        $this->assertQueryRejected(static fn (): int => DB::table('referral_reward_lifecycle_events')->where('id', $eventId)->update(['correlation_id' => 'mutated-correlation']));
        $this->assertQueryRejected(static fn (): int => DB::table('referral_reward_lifecycle_events')->where('id', $eventId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('referral_rewards')->where('id', $rewardId)->update(['amount_irr' => 1]));
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
