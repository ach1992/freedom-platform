<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement WAL-002 QUA-001 */
final class WalletMaintenanceCommandTest extends TestCase
{
    use DatabaseTruncation;

    public function test_json_maintenance_releases_expired_holds_reconciles_wallets_and_exposes_counts_only(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.maintenance.asset', 'asset');
        $walletId = $this->account('wallet.cash.maintenance.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 800_000);
        $holdId = $this->expiredActiveHold(
            $userId,
            $walletId,
            'wallet.hold.maintenance.expired.000001',
            200_000,
        );

        $expected = json_encode([
            'status' => 'healthy',
            'expired_holds' => [
                'examined' => 1,
                'released' => 1,
                'replayed' => 0,
                'review_count' => 0,
            ],
            'wallets' => [
                'examined' => 1,
                'reconciled' => 1,
                'initial' => 1,
                'matched' => 0,
                'refreshed' => 0,
                'review_count' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->artisan('wallet:maintenance', [
            '--hold-limit' => 100,
            '--wallet-limit' => 200,
            '--json' => true,
        ])->expectsOutput($expected)->assertExitCode(0);

        self::assertSame(WalletHoldStatus::Released->value, DB::table('wallet_holds')->where('id', $holdId)->value('status'));
        self::assertSame('system expired hold cleanup', DB::table('wallet_holds')->where('id', $holdId)->value('release_reason'));
        self::assertSame(1, DB::table('wallet_balance_snapshots')->count());
        self::assertSame(800_000, (int) DB::table('wallet_balance_snapshots')->value('available_balance_irr'));
        self::assertSame(1, DB::table('ledger_transactions')->count());
    }

    public function test_review_required_json_contains_no_hold_or_wallet_identifiers(): void
    {
        $userId = $this->user();
        $assetId = $this->account('system.wallet.maintenance.review.asset', 'asset');
        $walletId = $this->account('wallet.cash.maintenance.review.'.$userId, 'liability', $userId, 'cash');
        $this->fundWallet($assetId, $walletId, 500_000);
        $this->expiredActiveHold($userId, $walletId, 'invalid hold key', 100_000);

        $expected = json_encode([
            'status' => 'review_required',
            'expired_holds' => [
                'examined' => 1,
                'released' => 0,
                'replayed' => 0,
                'review_count' => 1,
            ],
            'wallets' => [
                'examined' => 1,
                'reconciled' => 1,
                'initial' => 1,
                'matched' => 0,
                'refreshed' => 0,
                'review_count' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->artisan('wallet:maintenance', [
            '--hold-limit' => 100,
            '--wallet-limit' => 200,
            '--json' => true,
        ])->expectsOutput($expected)->assertExitCode(1);

        self::assertSame(WalletHoldStatus::Active->value, DB::table('wallet_holds')->value('status'));
        self::assertSame(1, DB::table('wallet_balance_snapshots')->count());
    }

    public function test_wallet_limit_bounds_reconciliation_work_and_invalid_limits_fail_closed(): void
    {
        $firstUserId = $this->user();
        $secondUserId = $this->user();
        $assetId = $this->account('system.wallet.maintenance.limit.asset', 'asset');
        $firstWalletId = $this->account('wallet.cash.maintenance.limit.'.$firstUserId, 'liability', $firstUserId, 'cash');
        $secondWalletId = $this->account('wallet.cash.maintenance.limit.'.$secondUserId, 'liability', $secondUserId, 'cash');
        $this->fundWallet($assetId, $firstWalletId, 200_000, 'first');
        $this->fundWallet($assetId, $secondWalletId, 300_000, 'second');

        $expected = json_encode([
            'status' => 'healthy',
            'expired_holds' => [
                'examined' => 0,
                'released' => 0,
                'replayed' => 0,
                'review_count' => 0,
            ],
            'wallets' => [
                'examined' => 1,
                'reconciled' => 1,
                'initial' => 1,
                'matched' => 0,
                'refreshed' => 0,
                'review_count' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->artisan('wallet:maintenance', [
            '--hold-limit' => 1,
            '--wallet-limit' => 1,
            '--json' => true,
        ])->expectsOutput($expected)->assertExitCode(0);
        self::assertSame(1, DB::table('wallet_balance_snapshots')->count());
        self::assertSame($firstWalletId, (int) DB::table('wallet_balance_snapshots')->value('ledger_account_id'));

        $this->artisan('wallet:maintenance', [
            '--hold-limit' => 0,
            '--wallet-limit' => 1,
            '--json' => true,
        ])->expectsOutput('{"status":"invalid","code":"wallet_maintenance_invalid_input"}')
            ->assertExitCode(2);
    }

    public function test_wallet_maintenance_is_registered_in_the_single_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('wallet:maintenance')
            ->assertExitCode(0);
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
            'source_id' => 'maintenance-'.Str::lower((string) Str::ulid()),
            'status' => WalletHoldStatus::Active->value,
            'expires_at' => new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')),
            'captured_ledger_transaction_id' => null,
            'captured_at' => null,
            'released_at' => null,
            'release_reason' => null,
            'created_at' => now('UTC'),
        ]);
    }

    private function fundWallet(int $assetId, int $walletId, int $amount, string $suffix = 'default'): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.maintenance.fund.'.$suffix.'.'.$walletId,
            'wallet_topup_capture',
            'corr-wallet-maintenance-'.$suffix.'-'.$walletId,
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-wallet-maintenance-'.$suffix.'-'.$walletId,
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
}
