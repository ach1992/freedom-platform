<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletTransferService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement WAL-003 DAT-003 DAT-004 QUA-001 */
final class WalletTransferStateConstraintTest extends TestCase
{
    use DatabaseTruncation;

    public function test_cancelled_state_cannot_retain_confirmation_material_through_database_bypass(): void
    {
        $this->enableTransferPolicy();
        [$senderId] = $this->user();
        [$recipientId, $recipientPublicId] = $this->user();
        $assetId = $this->account('system.wallet.transfer.state.asset', 'asset');
        $senderWalletId = $this->account('wallet.cash.transfer.state.'.$senderId, 'liability', $senderId, 'cash');
        $this->account('wallet.cash.transfer.state.'.$recipientId, 'liability', $recipientId, 'cash');
        $this->account('system.wallet.transfer.fee', 'revenue');
        $this->fundWallet($assetId, $senderWalletId, 500_000);

        $prepared = $this->app->make(WalletTransferService::class)->prepare(
            'wallet.transfer.state.000001',
            $senderId,
            $recipientPublicId,
            'cash',
            IrrMoney::positive(200_000),
        );

        try {
            DB::table('wallet_transfers')
                ->where('id', $prepared->transferId)
                ->update([
                    'status' => 'cancelled',
                    'confirmation_key' => 'invalid.confirmation.material',
                    'confirmed_at' => now('UTC'),
                    'cancelled_at' => now('UTC'),
                    'cancel_reason' => 'database bypass attempt',
                ]);
            self::fail('Expected the hardened cancelled-state constraint to reject confirmation material.');
        } catch (QueryException) {
            self::assertSame('pending_confirmation', DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('status'));
            self::assertNull(DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('confirmation_key'));
            self::assertNull(DB::table('wallet_transfers')->where('id', $prepared->transferId)->value('confirmed_at'));
        }
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

    private function fundWallet(int $assetId, int $walletId, int $amount): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.transfer.state.fund.'.$walletId,
            'wallet_topup_capture',
            'corr-wallet-transfer-state',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-wallet-transfer-state',
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
