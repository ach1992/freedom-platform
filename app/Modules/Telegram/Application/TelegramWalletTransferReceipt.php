<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramWalletTransferReceipt
{
    public function __construct(
        public string $transferKey,
        public string $status,
        public int $recipientUserId,
        public string $recipientPublicId,
        public int $amountIrr,
        public int $feeIrr,
        public int $totalDebitIrr,
        public string $confirmationExpiresAt,
        public int $availableBalanceAfterHoldIrr,
        public bool $replayed,
    ) {
        if (preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $transferKey) !== 1
            || ! in_array($status, ['pending_confirmation', 'completed', 'cancelled'], true)
            || $recipientUserId < 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $recipientPublicId) !== 1
            || $amountIrr < 1
            || $feeIrr < 0
            || $totalDebitIrr !== $amountIrr + $feeIrr
            || trim($confirmationExpiresAt) === ''
            || $availableBalanceAfterHoldIrr < 0) {
            throw new InvalidArgumentException('Telegram wallet transfer receipt is invalid.');
        }
    }
}
