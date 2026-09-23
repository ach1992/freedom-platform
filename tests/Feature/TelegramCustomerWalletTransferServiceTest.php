<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramCustomerWalletTransferService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement WAL-003 BUY-003 SEC-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
final class TelegramCustomerWalletTransferServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_prepare_confirm_and_replay_preserve_one_ledger_effect_and_self_ownership(): void
    {
        $this->enableTransferPolicy();
        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        [$otherUserId] = $this->user();

        $assetId = $this->account('system.wallet.telegram.transfer.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.telegram.transfer.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.telegram.transfer.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('wallet.cash.telegram.transfer.'.$otherUserId, 'liability', $otherUserId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 1_000_000);

        $service = $this->app->make(TelegramCustomerWalletTransferService::class);
        self::assertTrue($service->availableForSelf($senderId, $senderId));

        $key = 'tg-wallet-transfer:'.str_repeat('a', 48);
        $prepared = $service->prepareForSelf(
            $senderId,
            $senderId,
            $recipientPublicId,
            400_000,
            $key,
        );
        self::assertSame('pending_confirmation', $prepared->status);
        self::assertSame(400_000, $prepared->amountIrr);
        self::assertSame(14_000, $prepared->feeIrr);
        self::assertSame(414_000, $prepared->totalDebitIrr);
        self::assertSame(586_000, $prepared->availableBalanceAfterHoldIrr);
        self::assertFalse($prepared->replayed);

        $replay = $service->prepareForSelf(
            $senderId,
            $senderId,
            $recipientPublicId,
            400_000,
            $key,
        );
        self::assertTrue($replay->replayed);
        self::assertSame(1, DB::table('wallet_transfers')->count());
        self::assertSame(1, DB::table('wallet_holds')->count());

        try {
            $service->confirmForSelf(
                $otherUserId,
                $otherUserId,
                $key,
                'tg-wallet-confirm:'.str_repeat('b', 48),
                'tg-wallet:'.str_repeat('c', 48),
            );
            self::fail('A foreign wallet-transfer actor must not confirm another sender transfer.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $confirmationKey = 'tg-wallet-confirm:'.str_repeat('d', 48);
        $completed = $service->confirmForSelf(
            $senderId,
            $senderId,
            $key,
            $confirmationKey,
            'tg-wallet:'.str_repeat('e', 48),
        );
        self::assertSame('completed', $completed->status);
        self::assertFalse($completed->replayed);
        self::assertSame(2, DB::table('ledger_transactions')->count());

        $completedReplay = $service->confirmForSelf(
            $senderId,
            $senderId,
            $key,
            $confirmationKey,
            'tg-wallet:'.str_repeat('f', 48),
        );
        self::assertTrue($completedReplay->replayed);
        self::assertSame(2, DB::table('ledger_transactions')->count());
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'completed')->count());
    }

    public function test_availability_fails_closed_and_cancel_releases_prepared_transfer_once(): void
    {
        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.telegram.cancel.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.telegram.cancel.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.telegram.cancel.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 700_000);

        $service = $this->app->make(TelegramCustomerWalletTransferService::class);
        self::assertFalse($service->availableForSelf($senderId, $senderId));

        $this->enableTransferPolicy();
        self::assertTrue($service->availableForSelf($senderId, $senderId));

        $key = 'tg-wallet-transfer:'.str_repeat('1', 48);
        $service->prepareForSelf($senderId, $senderId, $recipientPublicId, 300_000, $key);
        $cancelled = $service->cancelForSelf(
            $senderId,
            $senderId,
            $key,
            'telegram user cancelled transfer',
        );
        self::assertSame('cancelled', $cancelled->status);
        self::assertFalse($cancelled->replayed);
        self::assertSame(700_000, $cancelled->availableBalanceAfterHoldIrr);

        $cancelReplay = $service->cancelForSelf(
            $senderId,
            $senderId,
            $key,
            'telegram user cancelled transfer',
        );
        self::assertTrue($cancelReplay->replayed);
        self::assertSame(1, DB::table('ledger_transactions')->count());
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

    /** @return array{0:int,1:string} */
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

    private function fundWallet(int $assetId, int $walletId, int $amount): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.telegram.wallet.transfer.fund.'.$walletId,
            'wallet_topup_capture',
            'corr-telegram-wallet-transfer-fund',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-telegram-wallet-transfer',
        );
    }
}
