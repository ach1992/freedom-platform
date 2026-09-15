<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use InvalidArgumentException;

final readonly class AgentPricingResolutionRequest
{
    public string $pricingProfileCode;

    public function __construct(
        public string $resolutionKey,
        public int $userId,
        string $pricingProfileCode,
        public int $planOfferingId,
        public AgentPricingAction $action,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $resolutionKey) !== 1) {
            throw new InvalidArgumentException('Agent pricing resolution key is invalid.');
        }
        if ($userId < 1 || $planOfferingId < 1) {
            throw new InvalidArgumentException('Agent pricing resolution identifiers must be positive.');
        }
        $normalizedProfileCode = strtolower(trim($pricingProfileCode));
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $normalizedProfileCode) !== 1) {
            throw new InvalidArgumentException('Agent pricing profile code is invalid.');
        }
        $this->pricingProfileCode = $normalizedProfileCode;
    }
}
