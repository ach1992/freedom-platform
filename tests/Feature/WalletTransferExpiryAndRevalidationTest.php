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
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement WAL-003 DAT-002 DAT-003 DAT-004 QUA-001 */
final class WalletTransferExpiryAndRevalidationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_expired_confirmation_cancels_transfer_and_releases_hold_without_transfer_ledger_effect(): void
    {
        $clock = new MutableWalletTransferClock(new DateTimeImmutable('2026-08-08T00:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $this->enableTransferPolicy(60);

        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.expiry.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.expiry.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.transfer.expiry.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 500_000, 'expiry');

        $service = $this->app->make(WalletTransferService::class);
        $prepared = $service->prepare(
            'wallet.transfer.expiry.000001',
            $senderId,
            $recipientPublicId,
            'cash',
            IrrMoney::positive(200_000),
        );

        self::assertSame(WalletTransferStatus::PendingConfirmation, $prepared->status);
        self::assertSame(WalletHoldStatus::Active->value, DB::table('wallet_holds')->where('id', $prepared->walletHoldId)->value('status'));
        self::assertSame(288_000, $this->app->make(WalletHoldService::class)->balance($senderId, $senderWalletId)->availableBalance->amount);
        self::assertSame(1, DB::table('ledger_transactions')->count());

        $clock->setNow(new DateTimeImmutable('2026-08-08T00:01:01+00:00'));
        $expired = $service->confirm(
            'wallet.transfer.expiry.000001',
            'confirm.wallet.transfer.expiry',
            'corr-wallet-transfer-expiry',
        );

        self::assertSame(WalletTransferStatus::Cancelled, $expired->status);
        self::assertNull($expired->ledgerTransactionId);
        self::assertSame(WalletHoldStatus::Released->value, DB::table('wallet_holds')->where('id', $prepared->walletHoldId)->value('status'));
        self::assertSame('transfer confirmation expired', DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('cancel_reason'));
        self::assertNull(DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('confirmation_key'));
        self::assertNull(DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('ledger_transaction_id'));
        self::assertSame(500_000, $this->app->make(WalletHoldService::class)->balance($senderId, $senderWalletId)->availableBalance->amount);
        self::assertSame(1, DB::table('ledger_transactions')->count());
    }

    public function test_confirmation_revalidates_recipient_account_status_before_any_financial_effect(): void
    {
        $clock = new MutableWalletTransferClock(new DateTimeImmutable('2026-08-08T00:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);
        $this->enableTransferPolicy(900);

        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.revalidation.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.revalidation.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.transfer.revalidation.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 500_000, 'revalidation');

        $service = $this->app->make(WalletTransferService::class);
        $prepared = $service->prepare(
            'wallet.transfer.revalidation.000001',
            $senderId,
            $recipientPublicId,
            'cash',
            IrrMoney::positive(200_000),
        );
        DB::table('users')->where('id', $recipientId)->update(['account_status' => 'suspended']);

        try {
            $service->confirm(
                'wallet.transfer.revalidation.000001',
                'confirm.wallet.transfer.revalidation',
                'corr-wallet-transfer-revalidation',
            );
            self::fail('Expected current recipient state to reject confirmation.');
        } catch (DomainException $exception) {
            self::assertSame('Wallet transfer users must be active customers.', $exception->getMessage());
        }

        self::assertSame(WalletTransferStatus::PendingConfirmation->value, DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('status'));
        self::assertSame(WalletHoldStatus::Active->value, DB::table('wallet_holds')->where('id', $prepared->walletHoldId)->value('status'));
        self::assertNull(DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('ledger_transaction_id'));
        self::assertSame(288_000, $this->app->make(WalletHoldService::class)->balance($senderId, $senderWalletId)->availableBalance->amount);
        self::assertSame(1, DB::table('ledger_transactions')->count());
    }

    private function enableTransferPolicy(int $ttlSeconds): void
    {
        config()->set('wallet.transfers.enabled', true);
        config()->set('wallet.transfers.allowed_buckets', ['cash']);
        config()->set('wallet.transfers.minimum_irr', 100_000);
        config()->set('wallet.transfers.maximum_irr', 1_000_000);
        config()->set('wallet.transfers.daily_limit_irr', 1_500_000);
        config()->set('wallet.transfers.fixed_fee_irr', 10_000);
        config()->set('wallet.transfers.fee_basis_points', 100);
        config()->set('wallet.transfers.confirmation_ttl_seconds', $ttlSeconds);
        config()->set('wallet.transfers.fee_account_code', 'system.wallet.transfer.fee');
    }

    /** @return array{0: int, 1: string} */
    private function user(): array
    {
        $now = now('UTC');
        $publicId = (string) Str::ulid();
        $id = (int) DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'account_type' => 'customer',
            'account_status' => 'active',
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
            'ledger.wallet.transfer.hardening.fund.'.$suffix.'.'.$walletId,
            'wallet_topup_capture',
            'corr-wallet-transfer-hardening-'.$suffix,
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-wallet-transfer-hardening-'.$suffix,
        );
    }

    private function account(
        string $code,
        string $class,
        ?int $userId = null,
        ?string $bucket = null,
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

final class MutableWalletTransferClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function setNow(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
