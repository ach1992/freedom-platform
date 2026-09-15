<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use InvalidArgumentException;

final readonly class BenefitCodeRedemptionRequest
{
    public function __construct(
        public string $redemptionKey,
        public string $code,
        public int $userId,
        public ?int $planOfferingId,
        public ?int $promotionalWalletAccountId,
        public string $correlationId,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_-]{8,128}\z/', $redemptionKey) !== 1) {
            throw new InvalidArgumentException('Benefit code redemption key is invalid.');
        }
        if ($userId < 1 || ($planOfferingId !== null && $planOfferingId < 1) || ($promotionalWalletAccountId !== null && $promotionalWalletAccountId < 1)) {
            throw new InvalidArgumentException('Benefit code redemption IDs must be positive.');
        }
        if (preg_match('/\A[A-Za-z0-9:_-]{8,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Benefit code redemption correlation ID is invalid.');
        }
    }
}
