<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final readonly class QuoteDiscountConsumptionReceipt
{
    public function __construct(
        public string $consumptionPublicId,
        public string $resolutionPublicId,
        public string $discountedQuotePublicId,
        public string $configurationHash,
        public bool $replayed,
    ) {
        foreach ([$consumptionPublicId, $resolutionPublicId, $discountedQuotePublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Quote discount consumption public identity is invalid.');
            }
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $configurationHash) !== 1) {
            throw new InvalidArgumentException('Quote discount consumption hash identity is invalid.');
        }
    }
}
