<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseOrderReceipt
{
    public function __construct(
        public string $orderPublicId,
        public string $sourceQuotePublicId,
        public string $sourceQuoteConfigurationHash,
        public int $amountIrr,
        public string $currency,
        public bool $replayed,
    ) {
        foreach ([$orderPublicId, $sourceQuotePublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram purchase Order public identity is invalid.');
            }
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $sourceQuoteConfigurationHash) !== 1
            || $amountIrr < 1
            || $currency !== 'IRR') {
            throw new InvalidArgumentException('Telegram purchase Order commercial identity is invalid.');
        }
    }
}
