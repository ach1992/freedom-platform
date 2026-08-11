<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Application\WalletTransferService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Modules\Wallet\Domain\WalletTransferStatus;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement WAL-003 DAT-002 DAT-003 DAT-004 QUA-001 */
final class WalletTransferTest extends TestCase
{
    use DatabaseTruncation;

    public function test_policy_is_fail_closed_and_prepare_reserves_exact_fee_without_ledger_transfer(): void
    {
        [$senderId, $senderPublicId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.'.$senderId, 'liability', $senderId, 'cash');
        $recipientWalletId = $this->account('wallet.cash.transfer.'.$recipientId, 'liability', $recipientId, 'cash');
        $feeAccountId = $this->account('system.wallet.transfer.fee', 'revenue');
        unset($senderPublicId, $recipientWalletId, $feeAccountId);
        $this->fundWallet($assetId, $senderWalletId, 1_000_000, 'prepare');

        $service = $this->app->make(WalletTransferService::class);
        $this->assertDomainMessage(
            'Wallet transfers are disabled.',
            fn (): mixed => $service->prepare('wallet.transfer.prepare.000001', $senderId, $recipientPublicId, 'cash', IrrMoney::positive(400_000)),
        );
        self::assertSame(0, DB::table('wallet_transfers')->count());
        self::assertSame(0, DB::table('wallet_holds')->count());

        $this->enableTransferPolicy();
        $prepared = $service->prepare(
            'wallet.transfer.prepare.000001',
            $senderId,
            $recipientPublicId,
            'cash',
            IrrMoney::positive(400_000),
        );

        self::assertSame(WalletTransferStatus::PendingConfirmation, $prepared->status);
        self::assertSame($recipientId, $prepared->recipientUserId);
        self::assertSame(strtoupper($recipientPublicId), $prepared->recipientPublicId);
        self::assertSame(400_000, $prepared->amount->amount);
        self::assertSame(14_000, $prepared->fee->amount);
        self::assertSame(414_000, $prepared->totalDebit->amount);
        self::assertFalse($prepared->replayed);
        self::assertSame(1, DB::table('wallet_transfers')->count());
        self::assertSame(1, DB::table('wallet_holds')->count());
        self::assertSame(WalletHoldStatus::Active->value, DB::table('wallet_holds')->value('status'));
        self::assertSame(414_000, (int) DB::table('wallet_holds')->value('amount_irr'));
        self::assertSame(1, DB::table('ledger_transactions')->count());
        self::assertSame(586_000, $this->app->make(WalletHoldService::class)->balance($senderId, $senderWalletId)->availableBalance->amount);

        $replay = $service->prepare(
            'wallet.transfer.prepare.000001',
            $senderId,
            $recipientPublicId,
            'cash',
            IrrMoney::positive(400_000),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($prepared->transferId, $replay->transferId);
        self::assertSame(1, DB::table('wallet_transfers')->count());
        self::assertSame(1, DB::table('wallet_holds')->count());

        $this->assertRuntimeMessage(
            'Wallet transfer key conflict.',
            fn (): mixed => $service->prepare('wallet.transfer.prepare.000001', $senderId, $recipientPublicId, 'cash', IrrMoney::positive(400_001)),
        );
        self::assertSame(1, DB::table('wallet_transfers')->count());
        self::assertSame(1, DB::table('wallet_holds')->count());
    }

    public function test_confirmation_posts_one_atomic_sender_recipient_fee_effect_and_replays_exactly(): void
    {
        $this->enableTransferPolicy();
        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.confirm.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.confirm.'.$senderId, 'liability', $senderId, 'cash');
        $recipientWalletId = $this->account('wallet.cash.transfer.confirm.'.$recipientId, 'liability', $recipientId, 'cash');
        $feeAccountId = $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 1_000_000, 'confirm');

        $service = $this->app->make(WalletTransferService::class);
        $prepared = $service->prepare(
            'wallet.transfer.confirm.000001',
            $senderId,
            $recipientPublicId,
            'cash',
            IrrMoney::positive(400_000),
        );
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $completed = $service->confirm(
            'wallet.transfer.confirm.000001',
            'confirm.wallet.transfer.000001',
            'corr-wallet-transfer-000001',
        );
        self::assertSame(WalletTransferStatus::Completed, $completed->status);
        self::assertNotNull($completed->ledgerTransactionId);
        self::assertFalse($completed->replayed);
        self::assertSame(WalletHoldStatus::Captured->value, DB::table('wallet_holds')->where('id', $prepared->walletHoldId)->value('status'));
        self::assertSame(2, DB::table('ledger_transactions')->count());
        self::assertSame(5, DB::table('ledger_entries')->count());

        $entries = DB::table('ledger_entries')
            ->where('ledger_transaction_id', $completed->ledgerTransactionId)
            ->orderBy('ledger_account_id')
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->map(static fn (object $row): array => [(int) $row->ledger_account_id, $row->direction, (int) $row->amount_irr])
            ->all();
        $expected = [
            [$senderWalletId, 'debit', 414_000],
            [$recipientWalletId, 'credit', 400_000],
            [$feeAccountId, 'credit', 14_000],
        ];
        sort($entries);
        sort($expected);
        self::assertSame($expected, $entries);

        $holds = $this->app->make(WalletHoldService::class);
        self::assertSame(586_000, $holds->balance($senderId, $senderWalletId)->availableBalance->amount);
        self::assertSame(400_000, $holds->balance($recipientId, $recipientWalletId)->availableBalance->amount);

        $replay = $service->confirm(
            'wallet.transfer.confirm.000001',
            'confirm.wallet.transfer.000001',
            'corr-wallet-transfer-replay',
        );
        self::assertTrue($replay->replayed);
        self::assertSame($completed->ledgerTransactionId, $replay->ledgerTransactionId);
        self::assertSame(2, DB::table('ledger_transactions')->count());
        self::assertSame(5, DB::table('ledger_entries')->count());

        $this->assertRuntimeMessage(
            'Wallet transfer confirmation replay conflicts with the accepted confirmation.',
            fn (): mixed => $service->confirm('wallet.transfer.confirm.000001', 'confirm.wallet.transfer.different', 'corr-wallet-transfer-conflict'),
        );
        $this->assertRuntimeMessage(
            'Completed wallet transfer cannot be cancelled.',
            fn (): mixed => $service->cancel('wallet.transfer.confirm.000001', 'customer changed mind'),
        );
        self::assertSame(2, DB::table('ledger_transactions')->count());
    }

    public function test_recipient_identity_policy_and_daily_limits_are_revalidated_fail_closed(): void
    {
        $this->enableTransferPolicy();
        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        [$otherRecipientId, $otherRecipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.policy.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.policy.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.transfer.policy.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('wallet.cash.transfer.policy.'.$otherRecipientId, 'liability', $otherRecipientId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 2_000_000, 'policy');
        $service = $this->app->make(WalletTransferService::class);

        $this->assertDomainMessage(
            'Wallet transfer bucket is not allowed.',
            fn (): mixed => $service->prepare('wallet.transfer.policy.bucket', $senderId, $recipientPublicId, 'promotional', IrrMoney::positive(400_000)),
        );
        $this->assertDomainMessage(
            'Wallet transfer amount is outside the configured limits.',
            fn (): mixed => $service->prepare('wallet.transfer.policy.min', $senderId, $recipientPublicId, 'cash', IrrMoney::positive(99_999)),
        );

        $first = $service->prepare('wallet.transfer.policy.first', $senderId, $recipientPublicId, 'cash', IrrMoney::positive(800_000));
        self::assertSame(WalletTransferStatus::PendingConfirmation, $first->status);
        $this->assertDomainMessage(
            'Wallet transfer daily limit would be exceeded.',
            fn (): mixed => $service->prepare('wallet.transfer.policy.second', $senderId, $otherRecipientPublicId, 'cash', IrrMoney::positive(800_000)),
        );
        self::assertSame(1, DB::table('wallet_transfers')->count());
        self::assertSame(1, DB::table('wallet_holds')->count());

        DB::table('users')->where('id', $recipientId)->update(['public_id' => (string) Str::ulid()]);
        $this->assertRuntimeMessage(
            'Wallet transfer recipient identity changed after preparation.',
            fn (): mixed => $service->confirm('wallet.transfer.policy.first', 'confirm.wallet.transfer.policy', 'corr-wallet-transfer-policy'),
        );
        self::assertSame(1, DB::table('ledger_transactions')->count());
        self::assertSame(WalletTransferStatus::PendingConfirmation->value, DB::table('wallet_transfers')->where('id', $first->transferId)->value('status'));
    }

    public function test_cancel_releases_reservation_once_without_transfer_ledger_effect(): void
    {
        $this->enableTransferPolicy();
        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.cancel.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.cancel.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.transfer.cancel.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 700_000, 'cancel');

        $service = $this->app->make(WalletTransferService::class);
        $prepared = $service->prepare(
            'wallet.transfer.cancel.000001',
            $senderId,
            $recipientPublicId,
            'cash',
            IrrMoney::positive(300_000),
        );
        self::assertSame(387_000, $this->app->make(WalletHoldService::class)->balance($senderId, $senderWalletId)->availableBalance->amount);

        $cancelled = $service->cancel('wallet.transfer.cancel.000001', 'customer cancelled transfer');
        self::assertSame(WalletTransferStatus::Cancelled, $cancelled->status);
        self::assertFalse($cancelled->replayed);
        self::assertSame(WalletHoldStatus::Released->value, DB::table('wallet_holds')->where('id', $prepared->walletHoldId)->value('status'));
        self::assertSame(700_000, $this->app->make(WalletHoldService::class)->balance($senderId, $senderWalletId)->availableBalance->amount);
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $replay = $service->cancel('wallet.transfer.cancel.000001', 'customer cancelled transfer');
        self::assertTrue($replay->replayed);
        $this->assertRuntimeMessage(
            'Wallet transfer cancellation replay conflicts with the accepted reason.',
            fn (): mixed => $service->cancel('wallet.transfer.cancel.000001', 'different reason'),
        );
        $this->assertRuntimeMessage(
            'Cancelled wallet transfer cannot be confirmed.',
            fn (): mixed => $service->confirm('wallet.transfer.cancel.000001', 'confirm.wallet.transfer.cancel', 'corr-wallet-transfer-cancel'),
        );
        self::assertSame(1, DB::table('ledger_transactions')->count());
    }

    public function test_insufficient_balance_and_database_guards_leave_no_partial_transfer_effect(): void
    {
        $this->enableTransferPolicy();
        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.guard.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.guard.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.transfer.guard.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 200_000, 'guard');
        $service = $this->app->make(WalletTransferService::class);

        $this->assertDomainMessage(
            'Wallet available balance is insufficient for this hold.',
            fn (): mixed => $service->prepare('wallet.transfer.guard.insufficient', $senderId, $recipientPublicId, 'cash', IrrMoney::positive(200_000)),
        );
        self::assertSame(0, DB::table('wallet_transfers')->count());
        self::assertSame(0, DB::table('wallet_holds')->count());
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $prepared = $service->prepare('wallet.transfer.guard.immutable', $senderId, $recipientPublicId, 'cash', IrrMoney::positive(100_000));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_transfers')->where('id', $prepared->transferId)->update(['amount_irr' => 100_001]));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_transfers')->where('id', $prepared->transferId)->delete());
        self::assertSame(100_000, (int) DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('amount_irr'));

        $service->cancel('wallet.transfer.guard.immutable', 'guard cleanup');
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_transfers')->where('id', $prepared->transferId)->update(['cancel_reason' => 'tampered']));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_transfers')->where('id', $prepared->transferId)->delete());
        self::assertSame('guard cleanup', DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('cancel_reason'));
    }

    private function enableTransferPolicy(): void
    {
        config()->set('wallet.transfers.enabled', true);
        config()->set('wallet.transfers.allowed_buckets', ['cash']);
        config()->set('wallet.transfers.minimum_irr', 100_000);
        config()->set('wallet.transfers.maximum_irr', 1_000_000);
        config()->set('wallet.transfers.daily_limit_irr', 1_500_000);
        config()->set('wallet.transfers.fixed_fee_irr', 10_000);
        config()->set('wallet.transfers.fee_basis_points', 100);
        config()->set('wallet.transfers.confirmation_ttl_seconds', 900);
        config()->set('wallet.transfers.fee_account_code', 'system.wallet.transfer.fee');
    }

    /** @return array{0: int, 1: string} */
    private function user(string $status = 'active'): array
    {
        $now = now('UTC');
        $publicId = (string) Str::ulid();
        $id = (int) DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'account_type' => 'customer',
            'account_status' => $status,
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$id, $publicId];
    }

    private function fundWallet(int $assetId, int $walletId, int $amount, string $suffix): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.transfer.fund.'.$suffix.'.'.$walletId,
            'wallet_topup_capture',
            'corr-wallet-transfer-fund-'.$suffix,
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-wallet-transfer-'.$suffix,
        );
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
