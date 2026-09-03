<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final readonly class QuoteDiscountAuthorization
{
    public function __construct(
        public string $grantPublicId,
        public string $grantConfigurationHash,
        public string $resolutionPublicId,
        public string $resolutionConfigurationHash,
        public string $sourceQuotePublicId,
        public string $sourceQuoteConfigurationHash,
        public string $ruleCode,
        public int $discountIrr,
        public bool $replayed,
    ) {
        foreach ([$grantPublicId, $resolutionPublicId, $sourceQuotePublicId] as $publicId) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
                throw new InvalidArgumentException('Quote discount authorization public identity is invalid.');
            }
        }
        foreach ([$grantConfigurationHash, $resolutionConfigurationHash, $sourceQuoteConfigurationHash] as $hash) {
            if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                throw new InvalidArgumentException('Quote discount authorization hash identity is invalid.');
            }
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $ruleCode) !== 1 || $discountIrr < 1) {
            throw new InvalidArgumentException('Quote discount authorization commercial identity is invalid.');
        }
    }
}
