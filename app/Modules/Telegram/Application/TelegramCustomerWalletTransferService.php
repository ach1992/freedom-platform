<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Wallet\Application\WalletSelfBalanceService;
use App\Modules\Wallet\Application\WalletTransferReceipt;
use App\Modules\Wallet\Application\WalletTransferService;
use App\Modules\Wallet\Domain\IrrMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerWalletTransferService
{
    private const BUCKET = 'cash';

    public function __construct(
        private DatabaseManager $database,
        private WalletTransferService $transfers,
        private WalletSelfBalanceService $balances,
    ) {}

    public function availableForSelf(int $actorUserId, int $subjectUserId): bool
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            return false;
        }
        if (config('wallet.transfers.enabled', false) !== true) {
            return false;
        }
        $allowed = config('wallet.transfers.allowed_buckets', []);
        if (! is_array($allowed) || ! in_array(self::BUCKET, $allowed, true)) {
            return false;
        }
        $minimum = config('wallet.transfers.minimum_irr');
        $maximum = config('wallet.transfers.maximum_irr');
        $dailyLimit = config('wallet.transfers.daily_limit_irr');
        $ttl = config('wallet.transfers.confirmation_ttl_seconds');
        if (! is_int($minimum) || $minimum < 1
            || ! is_int($maximum) || $maximum < $minimum
            || ! is_int($dailyLimit) || $dailyLimit < $maximum
            || ! is_int($ttl) || $ttl < 1 || $ttl > 86400) {
            return false;
        }
        $fixedFee = config('wallet.transfers.fixed_fee_irr');
        $feeBasisPoints = config('wallet.transfers.fee_basis_points');
        if (! is_int($fixedFee) || $fixedFee < 0
            || ! is_int($feeBasisPoints) || $feeBasisPoints < 0 || $feeBasisPoints > 10000) {
            return false;
        }
        if (($fixedFee > 0 || $feeBasisPoints > 0)
            && (! is_string(config('wallet.transfers.fee_account_code'))
                || trim((string) config('wallet.transfers.fee_account_code')) === '')) {
            return false;
        }
        $user = $this->database->connection()->table('users')
            ->where('id', $subjectUserId)
            ->first(['account_type', 'account_status']);
        if ($user === null || $user->account_type !== 'customer' || $user->account_status !== 'active') {
            return false;
        }

        return $this->balances->forSelf($subjectUserId, $actorUserId)->cashAccountExists;
    }

    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $recipientPublicId,
        int $amountIrr,
        string $transferKey,
    ): TelegramWalletTransferReceipt {
        $this->assertSelf($actorUserId, $subjectUserId);
        if (! $this->availableForSelf($actorUserId, $subjectUserId)) {
            throw new AuthorizationException('Telegram wallet transfer is unavailable.');
        }
        if ($amountIrr < 1) {
            throw new RuntimeException('Telegram wallet transfer amount is invalid.');
        }
        $this->assertTransferKey($transferKey);

        $receipt = $this->transfers->prepare(
            $transferKey,
            $subjectUserId,
            $recipientPublicId,
            self::BUCKET,
            IrrMoney::positive($amountIrr),
        );

        return $this->receipt($subjectUserId, $actorUserId, $transferKey, $receipt);
    }

    public function confirmForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $transferKey,
        string $confirmationKey,
        string $correlationId,
    ): TelegramWalletTransferReceipt {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertTransferKey($transferKey);
        $this->assertOwnedTransfer($subjectUserId, $transferKey);

        return $this->receipt(
            $subjectUserId,
            $actorUserId,
            $transferKey,
            $this->transfers->confirm($transferKey, $confirmationKey, $correlationId),
        );
    }

    public function cancelForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $transferKey,
        string $reason,
    ): TelegramWalletTransferReceipt {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertTransferKey($transferKey);
        $this->assertOwnedTransfer($subjectUserId, $transferKey);

        return $this->receipt(
            $subjectUserId,
            $actorUserId,
            $transferKey,
            $this->transfers->cancel($transferKey, $reason),
        );
    }

    private function receipt(
        int $subjectUserId,
        int $actorUserId,
        string $transferKey,
        WalletTransferReceipt $receipt,
    ): TelegramWalletTransferReceipt {
        $row = $this->database->connection()->table('wallet_transfers')
            ->where('id', $receipt->transferId)
            ->where('transfer_key', $transferKey)
            ->where('sender_user_id', $subjectUserId)
            ->first(['confirmation_expires_at']);
        if ($row === null || ! is_string($row->confirmation_expires_at) || trim($row->confirmation_expires_at) === '') {
            throw new RuntimeException('Telegram wallet transfer projection is unavailable.');
        }
        $balance = $this->balances->forSelf($subjectUserId, $actorUserId);

        return new TelegramWalletTransferReceipt(
            $transferKey,
            $receipt->status->value,
            $receipt->recipientUserId,
            $receipt->recipientPublicId,
            $receipt->amount->amount,
            $receipt->fee->amount,
            $receipt->totalDebit->amount,
            $row->confirmation_expires_at,
            $balance->cashAvailableBalanceIrr,
            $receipt->replayed,
        );
    }

    private function assertOwnedTransfer(int $subjectUserId, string $transferKey): void
    {
        $senderUserId = $this->database->connection()->table('wallet_transfers')
            ->where('transfer_key', $transferKey)
            ->value('sender_user_id');
        if ((int) $senderUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram wallet transfer ownership denied.');
        }
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram wallet transfer self access denied.');
        }
    }

    private function assertTransferKey(string $transferKey): void
    {
        if (preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $transferKey) !== 1) {
            throw new RuntimeException('Telegram wallet transfer identity is invalid.');
        }
    }
}
