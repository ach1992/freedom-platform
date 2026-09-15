<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\PromotionAction;
use InvalidArgumentException;

final readonly class PromotionSpecificResolutionRequest
{
    public function __construct(
        public string $resolutionKey,
        public int $userId,
        public int $planOfferingId,
        public PromotionAction $action,
        public int $inputPriceIrr,
        public int $pricingRuleVersionId,
        public string $ruleConfigurationHash,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $resolutionKey) !== 1) {
            throw new InvalidArgumentException('Promotion specific resolution key is invalid.');
        }
        if ($userId < 1 || $planOfferingId < 1 || $pricingRuleVersionId < 1) {
            throw new InvalidArgumentException('Promotion specific resolution identifiers must be positive.');
        }
        if ($inputPriceIrr < 1) {
            throw new InvalidArgumentException('Promotion specific resolution price must be positive integer IRR.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $ruleConfigurationHash) !== 1) {
            throw new InvalidArgumentException('Promotion specific rule configuration hash is invalid.');
        }
    }
}
