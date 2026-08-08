<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
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
final class WalletHoldLifecycleTest extends TestCase
{
    use DatabaseTruncation;

    public function test_place_reserves_available_balance_and_replay_or_conflict_has_no_second_effect(): void
    {
        self::assertTrue(Schema::hasTable('wallet_holds'));

        $userId = $this->user();
        $assetId = $this->account('system.wallet.hold.asset', 'asset');
        $walletId = $this->account('wallet.cash.hold.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 1_000_000);
        $service = $this->app->make(WalletHoldService::class);

        $before = $service->balance($userId, $walletId);
        self::assertSame(1_000_000, $before->ledgerBalance->amount);
        self::assertSame(0, $before->activeHolds->amount);
        self::assertSame(1_000_000, $before->availableBalance->amount);

        $expiresAt = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));
        $hold = $service->place(
            'wallet.hold.order.000001',
            $userId,
            $walletId,
            IrrMoney::positive(400_000),
            'order',
            'order-test-000001',
            $expiresAt,
        );
        self::assertFalse($hold->replayed);
        self::assertSame(WalletHoldStatus::Active, $hold->status);

        $after = $service->balance($userId, $walletId);
        self::assertSame(1_000_000, $after->ledgerBalance->amount);
        self::assertSame(400_000, $after->activeHolds->amount);
        self::assertSame(600_000, $after->availableBalance->amount);
        self::assertSame(1, DB::table('wallet_holds')->count());
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $replay = $service->place(
            'wallet.hold.order.000001',
            $userId,
            $walletId,
            IrrMoney::positive(400_000),
            'order',
            'order-test-000001',
            $expiresAt,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($hold->holdId, $replay->holdId);
        self::assertSame(1, DB::table('wallet_holds')->count());

        $this->assertRuntimeMessage('Wallet hold key conflict.', fn (): mixed => $service->place(
            'wallet.hold.order.000001',
            $userId,
            $walletId,
            IrrMoney::positive(400_001),
            'order',
            'order-test-000001',
            $expiresAt,
        ));
        self::assertSame(400_000, (int) DB::table('wallet_holds')->where('id', $hold->holdId)->value('amount_irr'));

        $this->assertDomainMessage(
            'Wallet available balance is insufficient for this hold.',
            fn (): mixed => $service->place(
                'wallet.hold.order.000002',
                $userId,
                $walletId,
                IrrMoney::positive(700_000),
                'order',
                'order-test-000002',
                new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')),
            ),
        );
        self::assertSame(1, DB::table('wallet_holds')->count());
        self::assertSame(600_000, $service->balance($userId, $walletId)->availableBalance->amount);
    }

    public function test_capture_posts_exactly_one_ledger_effect_and_replay_cannot_change_offset(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.capture.asset', 'asset');
        $revenueId = $this->account('system.wallet.capture.revenue', 'revenue');
        $otherRevenueId = $this->account('system.wallet.capture.other', 'revenue');
        $walletId = $this->account('wallet.cash.capture.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 1_000_000);
        $service = $this->app->make(WalletHoldService::class);

        $placed = $service->place(
            'wallet.hold.capture.000001',
            $userId,
            $walletId,
            IrrMoney::positive(400_000),
            'order',
            'order-capture-000001',
            new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
        $captured = $service->capture('wallet.hold.capture.000001', $revenueId, 'corr-wallet-capture-000001');
        self::assertFalse($captured->replayed);
        self::assertSame($placed->holdId, $captured->holdId);
        self::assertSame(WalletHoldStatus::Captured, $captured->status);
        self::assertNotNull($captured->capturedLedgerTransactionId);

        $balance = $service->balance($userId, $walletId);
        self::assertSame(600_000, $balance->ledgerBalance->amount);
        self::assertSame(0, $balance->activeHolds->amount);
        self::assertSame(600_000, $balance->availableBalance->amount);
        self::assertSame(2, DB::table('ledger_transactions')->count());
        self::assertSame(4, DB::table('ledger_entries')->count());

        $replay = $service->capture('wallet.hold.capture.000001', $revenueId, 'corr-wallet-capture-replay');
        self::assertTrue($replay->replayed);
        self::assertSame($captured->capturedLedgerTransactionId, $replay->capturedLedgerTransactionId);
        self::assertSame(2, DB::table('ledger_transactions')->count());
        self::assertSame(4, DB::table('ledger_entries')->count());

        $this->assertRuntimeMessage(
            'Wallet hold capture replay conflicts with the accepted ledger effect.',
            fn (): mixed => $service->capture('wallet.hold.capture.000001', $otherRevenueId, 'corr-wallet-capture-conflict'),
        );
        $this->assertRuntimeMessage(
            'Captured wallet hold cannot be released.',
            fn (): mixed => $service->release('wallet.hold.capture.000001', 'order canceled'),
        );
        self::assertSame(2, DB::table('ledger_transactions')->count());
    }

    public function test_release_restores_available_balance_without_ledger_effect_and_is_idempotent(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.release.asset', 'asset');
        $revenueId = $this->account('system.wallet.release.revenue', 'revenue');
        $walletId = $this->account('wallet.cash.release.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 900_000);
        $service = $this->app->make(WalletHoldService::class);

        $service->place(
            'wallet.hold.release.000001',
            $userId,
            $walletId,
            IrrMoney::positive(300_000),
            'order',
            'order-release-000001',
            new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
        self::assertSame(600_000, $service->balance($userId, $walletId)->availableBalance->amount);

        $released = $service->release('wallet.hold.release.000001', 'order canceled');
        self::assertFalse($released->replayed);
        self::assertSame(WalletHoldStatus::Released, $released->status);
        self::assertSame(900_000, $service->balance($userId, $walletId)->availableBalance->amount);
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $replay = $service->release('wallet.hold.release.000001', 'order canceled');
        self::assertTrue($replay->replayed);
        self::assertSame($released->holdId, $replay->holdId);
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $this->assertRuntimeMessage(
            'Wallet hold release replay conflicts with the accepted reason.',
            fn (): mixed => $service->release('wallet.hold.release.000001', 'different reason'),
        );
        $this->assertRuntimeMessage(
            'Released wallet hold cannot be captured.',
            fn (): mixed => $service->capture('wallet.hold.release.000001', $revenueId, 'corr-wallet-release-capture'),
        );
    }

    public function test_expired_active_hold_remains_reserved_until_explicit_release_and_cannot_be_captured(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.expired.asset', 'asset');
        $revenueId = $this->account('system.wallet.expired.revenue', 'revenue');
        $walletId = $this->account('wallet.cash.expired.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 500_000);
        $service = $this->app->make(WalletHoldService::class);

        DB::table('wallet_holds')->insert([
            'hold_key' => 'wallet.hold.expired.000001',
            'payload_hash' => hash('sha256', 'wallet.hold.expired.000001'),
            'owner_user_id' => $userId,
            'ledger_account_id' => $walletId,
            'amount_irr' => 400_000,
            'source_type' => 'order',
            'source_id' => 'order-expired-000001',
            'status' => 'active',
            'expires_at' => new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')),
            'captured_ledger_transaction_id' => null,
            'captured_at' => null,
            'released_at' => null,
            'release_reason' => null,
            'created_at' => now('UTC'),
        ]);

        $balance = $service->balance($userId, $walletId);
        self::assertSame(500_000, $balance->ledgerBalance->amount);
        self::assertSame(400_000, $balance->activeHolds->amount);
        self::assertSame(100_000, $balance->availableBalance->amount);

        $this->assertRuntimeMessage(
            'Expired wallet hold must be released before further action.',
            fn (): mixed => $service->capture('wallet.hold.expired.000001', $revenueId, 'corr-wallet-expired-capture'),
        );
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $service->release('wallet.hold.expired.000001', 'expired hold cleanup');
        self::assertSame(500_000, $service->balance($userId, $walletId)->availableBalance->amount);
    }

    public function test_database_guards_make_hold_identity_terminal_states_and_rows_immutable(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.guard.asset', 'asset');
        $walletId = $this->account('wallet.cash.guard.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 600_000);
        $service = $this->app->make(WalletHoldService::class);
        $hold = $service->place(
            'wallet.hold.guard.000001',
            $userId,
            $walletId,
            IrrMoney::positive(200_000),
            'order',
            'order-guard-000001',
            new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );

        $this->assertQueryRejected(static fn (): int => DB::table('wallet_holds')->where('id', $hold->holdId)->update(['amount_irr' => 200_001]));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_holds')->where('id', $hold->holdId)->delete());
        self::assertSame(200_000, (int) DB::table('wallet_holds')->where('id', $hold->holdId)->value('amount_irr'));

        $service->release('wallet.hold.guard.000001', 'guard release');
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_holds')->where('id', $hold->holdId)->update(['release_reason' => 'mutated release']));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_holds')->where('id', $hold->holdId)->delete());
        self::assertSame('guard release', DB::table('wallet_holds')->where('id', $hold->holdId)->value('release_reason'));
    }

    private function fundWallet(int $assetId, int $walletId, int $amount): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.fund.000001',
            'wallet_topup_capture',
            'corr-wallet-fund-000001',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-wallet-fund-000001',
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
