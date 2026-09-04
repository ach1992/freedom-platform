<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseWalletReservation
{
    public function __construct(
        public string $paymentIntentPublicId,
        public string $orderPublicId,
        public string $quotePublicId,
        public string $decisionPublicId,
        public int $amountIrr,
        public int $availableBalanceAfterHoldIrr,
        public bool $replayed,
    ) {
        foreach ([$paymentIntentPublicId, $orderPublicId, $quotePublicId, $decisionPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram wallet reservation public identity is invalid.');
            }
        }
        if ($amountIrr < 1 || $availableBalanceAfterHoldIrr < 0) {
            throw new InvalidArgumentException('Telegram wallet reservation amount is invalid.');
        }
    }
}
