<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseWalletPaid
{
    public function __construct(
        public string $paymentIntentPublicId,
        public string $orderPublicId,
        public string $purchaseSettlementPublicId,
        public string $quotePublicId,
        public int $amountIrr,
        public bool $replayed,
    ) {
        foreach ([$paymentIntentPublicId, $orderPublicId, $purchaseSettlementPublicId, $quotePublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram wallet paid public identity is invalid.');
            }
        }
        if ($amountIrr < 1) {
            throw new InvalidArgumentException('Telegram wallet paid amount is invalid.');
        }
    }
}
