<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerPurchaseDiscountQuotePreview
{
    public function __construct(
        public TelegramCustomerPurchaseQuotePreview $quote,
        public string $discountConsumptionPublicId,
        public string $discountConsumptionConfigurationHash,
        public string $promotionResolutionPublicId,
        public bool $replayed,
    ) {
        foreach ([$discountConsumptionPublicId, $promotionResolutionPublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Telegram discounted Quote public identity is invalid.');
            }
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $discountConsumptionConfigurationHash) !== 1) {
            throw new InvalidArgumentException('Telegram discounted Quote consumption hash is invalid.');
        }
        if ($quote->discountIrr < 1) {
            throw new InvalidArgumentException('Telegram discounted Quote must contain a positive discount.');
        }
    }
}
