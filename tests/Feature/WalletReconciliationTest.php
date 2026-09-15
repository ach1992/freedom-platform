<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Wallet\Application\ExpiredWalletHoldCleanupService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Application\WalletReconciliationService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Modules\Wallet\Domain\WalletReconciliationStatus;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
final class WalletReconciliationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_reconciliation_appends_authoritative_snapshots_and_stale_snapshot_never_authorizes_balance(): void
    {
        self::assertTrue(Schema::hasTable('wallet_balance_snapshots'));

        $userId = $this->user();
        $assetId = $this->account('system.wallet.reconcile.asset', 'asset');
        $walletId = $this->account('wallet.cash.reconcile.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 1_000_000);

        $holds = $this->app->make(WalletHoldService::class);
        $reconciliation = $this->app->make(WalletReconciliationService::class);
        $holds->place(
            'wallet.hold.reconcile.000001',
            $userId,
            $walletId,
            IrrMoney::positive(250_000),
            'order',
            'order-reconcile-000001',
            new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );

        $first = $reconciliation->reconcile($userId, $walletId);
        self::assertSame(WalletReconciliationStatus::Initial, $first->status);
        self::assertNull($first->comparedSnapshotId);
        self::assertSame(1_000_000, $first->balance->ledgerBalance->amount);
        self::assertSame(250_000, $first->balance->activeHolds->amount);
        self::assertSame(750_000, $first->balance->availableBalance->amount);
        self::assertSame(64, strlen($first->sourceFingerprint));

        $second = $reconciliation->reconcile($userId, $walletId);
        self::assertSame(WalletReconciliationStatus::Matched, $second->status);
        self::assertSame($first->snapshotId, $second->comparedSnapshotId);
        self::assertSame($first->sourceFingerprint, $second->sourceFingerprint);

        $holds->place(
            'wallet.hold.reconcile.000002',
            $userId,
            $walletId,
            IrrMoney::positive(100_000),
            'order',
            'order-reconcile-000002',
            new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')),
        );
        $third = $reconciliation->reconcile($userId, $walletId);
        self::assertSame(WalletReconciliationStatus::Refreshed, $third->status);
        self::assertSame($second->snapshotId, $third->comparedSnapshotId);
        self::assertNotSame($second->sourceFingerprint, $third->sourceFingerprint);
        self::assertSame(350_000, $third->balance->activeHolds->amount);
        self::assertSame(650_000, $third->balance->availableBalance->amount);
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $staleSnapshotId = (int) DB::table('wallet_balance_snapshots')->insertGetId([
            'ledger_account_id' => $walletId,
            'ledger_balance_irr' => 999_999,
            'active_holds_irr' => 0,
            'available_balance_irr' => 999_999,
            'last_finalized_ledger_entry_id' => null,
            'last_active_hold_id' => null,
            'source_fingerprint' => hash('sha256', 'stale-wallet-snapshot'),
            'comparison_status' => WalletReconciliationStatus::Refreshed->value,
            'compared_snapshot_id' => $third->snapshotId,
            'calculated_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]);

        $authoritative = $holds->balance($userId, $walletId);
        self::assertSame(1_000_000, $authoritative->ledgerBalance->amount);
        self::assertSame(350_000, $authoritative->activeHolds->amount);
        self::assertSame(650_000, $authoritative->availableBalance->amount);

        $fourth = $reconciliation->reconcile($userId, $walletId);
        self::assertSame(WalletReconciliationStatus::Refreshed, $fourth->status);
        self::assertSame($staleSnapshotId, $fourth->comparedSnapshotId);
        self::assertSame(650_000, $fourth->balance->availableBalance->amount);
        self::assertSame(5, DB::table('wallet_balance_snapshots')->count());
        self::assertSame(750_000, (int) DB::table('wallet_balance_snapshots')->where('id', $first->snapshotId)->value('available_balance_irr'));

        $this->assertQueryRejected(static fn (): int => DB::table('wallet_balance_snapshots')->where('id', $fourth->snapshotId)->update(['available_balance_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_balance_snapshots')->where('id', $fourth->snapshotId)->delete());
        self::assertSame(5, DB::table('wallet_balance_snapshots')->count());
    }

    public function test_reconciliation_fails_closed_when_active_holds_exceed_ledger_balance(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.reconcile.invalid.asset', 'asset');
        $walletId = $this->account('wallet.cash.reconcile.invalid.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 500_000);

        DB::table('wallet_holds')->insert([
            'hold_key' => 'wallet.hold.reconcile.invalid.000001',
            'payload_hash' => hash('sha256', 'wallet.hold.reconcile.invalid.000001'),
            'owner_user_id' => $userId,
            'ledger_account_id' => $walletId,
            'amount_irr' => 600_000,
            'source_type' => 'order',
            'source_id' => 'order-reconcile-invalid-000001',
            'status' => WalletHoldStatus::Active->value,
            'expires_at' => new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
            'captured_ledger_transaction_id' => null,
            'captured_at' => null,
            'released_at' => null,
            'release_reason' => null,
            'created_at' => now('UTC'),
        ]);

        $this->assertRuntimeMessage(
            'Wallet active holds exceed ledger balance and require reconciliation.',
            fn (): mixed => $this->app->make(WalletReconciliationService::class)->reconcile($userId, $walletId),
        );
        self::assertSame(0, DB::table('wallet_balance_snapshots')->count());
    }

    public function test_expired_hold_cleanup_is_bounded_rerunnable_and_surfaces_review_rows(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.cleanup.asset', 'asset');
        $revenueId = $this->account('system.wallet.cleanup.revenue', 'revenue');
        $walletId = $this->account('wallet.cash.cleanup.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 1_000_000);
        $holds = $this->app->make(WalletHoldService::class);

        $future = $holds->place(
            'wallet.hold.cleanup.future.000001',
            $userId,
            $walletId,
            IrrMoney::positive(100_000),
            'order',
            'order-cleanup-future-000001',
            new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')),
        );
        $captured = $holds->place(
            'wallet.hold.cleanup.captured.000001',
            $userId,
            $walletId,
            IrrMoney::positive(100_000),
            'order',
            'order-cleanup-captured-000001',
            new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')),
        );
        $holds->capture('wallet.hold.cleanup.captured.000001', $revenueId, 'corr-wallet-cleanup-capture-000001');
        $released = $holds->place(
            'wallet.hold.cleanup.released.000001',
            $userId,
            $walletId,
            IrrMoney::positive(100_000),
            'order',
            'order-cleanup-released-000001',
            new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')),
        );
        $holds->release('wallet.hold.cleanup.released.000001', 'customer canceled');

        $expiredOneId = $this->expiredActiveHold($userId, $walletId, 'wallet.hold.cleanup.expired.000001', 150_000);
        $expiredTwoId = $this->expiredActiveHold($userId, $walletId, 'wallet.hold.cleanup.expired.000002', 150_000);
        $reviewId = $this->expiredActiveHold($userId, $walletId, 'invalid hold key', 50_000);

        $cleanup = $this->app->make(ExpiredWalletHoldCleanupService::class);
        $first = $cleanup->cleanup(1);
        self::assertSame(1, $first->examined);
        self::assertSame(1, $first->released);
        self::assertSame(0, $first->replayed);
        self::assertFalse($first->requiresReview());
        self::assertSame(WalletHoldStatus::Released->value, DB::table('wallet_holds')->where('id', $expiredOneId)->value('status'));

        $second = $cleanup->cleanup(100);
        self::assertSame(2, $second->examined);
        self::assertSame(1, $second->released);
        self::assertSame(0, $second->replayed);
        self::assertTrue($second->requiresReview());
        self::assertSame([$reviewId], $second->reviewHoldIds);
        self::assertSame(WalletHoldStatus::Released->value, DB::table('wallet_holds')->where('id', $expiredTwoId)->value('status'));
        self::assertSame('system expired hold cleanup', DB::table('wallet_holds')->where('id', $expiredTwoId)->value('release_reason'));
        self::assertSame(WalletHoldStatus::Active->value, DB::table('wallet_holds')->where('id', $reviewId)->value('status'));

        $third = $cleanup->cleanup(100);
        self::assertSame(1, $third->examined);
        self::assertSame(0, $third->released);
        self::assertSame(0, $third->replayed);
        self::assertSame([$reviewId], $third->reviewHoldIds);

        self::assertSame(WalletHoldStatus::Active->value, DB::table('wallet_holds')->where('id', $future->holdId)->value('status'));
        self::assertSame(WalletHoldStatus::Captured->value, DB::table('wallet_holds')->where('id', $captured->holdId)->value('status'));
        self::assertSame(WalletHoldStatus::Released->value, DB::table('wallet_holds')->where('id', $released->holdId)->value('status'));
        self::assertSame(2, DB::table('ledger_transactions')->count());
        self::assertSame(4, DB::table('ledger_entries')->count());

        $this->assertDomainMessage(
            'Expired wallet hold cleanup limit must be between 1 and 500.',
            fn (): mixed => $cleanup->cleanup(0),
        );
    }

    private function expiredActiveHold(int $userId, int $walletId, string $holdKey, int $amount): int
    {
        return (int) DB::table('wallet_holds')->insertGetId([
            'hold_key' => $holdKey,
            'payload_hash' => hash('sha256', $holdKey.'|'.$amount),
            'owner_user_id' => $userId,
            'ledger_account_id' => $walletId,
            'amount_irr' => $amount,
            'source_type' => 'order',
            'source_id' => 'expired-'.$amount.'-'.Str::lower((string) Str::ulid()),
            'status' => WalletHoldStatus::Active->value,
            'expires_at' => new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')),
            'captured_ledger_transaction_id' => null,
            'captured_at' => null,
            'released_at' => null,
            'release_reason' => null,
            'created_at' => now('UTC'),
        ]);
    }

    private function fundWallet(int $assetId, int $walletId, int $amount): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.reconcile.fund.'.$walletId,
            'wallet_topup_capture',
            'corr-wallet-reconcile-'.$walletId,
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-wallet-reconcile-'.$walletId,
        );
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function account(
        string $code,
        string $class,
        ?int $userId = null,
        ?string $bucket = null,
        bool $active = true,
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => $active,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertDomainMessage(string $message, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected domain rejection.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertRuntimeMessage(string $message, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected runtime rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
