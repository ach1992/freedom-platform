<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final readonly class QuoteDiscountConsumptionRequest
{
    public function __construct(
        public string $consumptionKey,
        public int $actorUserId,
        public QuoteDiscountAuthorization $authorization,
        public string $discountedQuotePublicId,
        public string $discountedQuoteConfigurationHash,
        public string $correlationId,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $consumptionKey) !== 1
            || $actorUserId < 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $discountedQuotePublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $discountedQuoteConfigurationHash) !== 1
            || preg_match('/\A[A-Za-z0-9:_.-]{8,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Quote discount consumption request is invalid.');
        }
    }
}
