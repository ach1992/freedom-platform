<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final readonly class QuoteDiscountAuthorizationRequest
{
    public function __construct(
        public string $authorizationKey,
        public int $actorUserId,
        public string $sourceQuotePublicId,
        public string $sourceQuoteConfigurationHash,
        public string $code,
        public string $correlationId,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $authorizationKey) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $sourceQuotePublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceQuoteConfigurationHash) !== 1
            || preg_match('/\A[A-Za-z0-9:_.-]{8,64}\z/', $correlationId) !== 1
            || $actorUserId < 1
            || $code === ''
            || strlen($code) > 256) {
            throw new InvalidArgumentException('Quote discount authorization request is invalid.');
        }
    }
}
