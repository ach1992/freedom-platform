<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\Agents\Domain\AgentPricingAction;

final readonly class AgentPricingResolutionReceipt
{
    public function __construct(
        public int $resolutionId,
        public string $resolutionPublicId,
        public string $resolutionKey,
        public int $userId,
        public int $agentProfileId,
        public int $pricingProfileId,
        public string $pricingProfilePublicId,
        public string $pricingProfileCode,
        public int $pricingProfileVersion,
        public string $pricingProfileConfigurationHash,
        public int $planOfferingId,
        public int $productId,
        public int $salesServerId,
        public AgentPricingAction $action,
        public ?int $ruleId,
        public ?string $rulePublicId,
        public ?string $ruleCode,
        public ?int $ruleVersion,
        public ?string $ruleConfigurationHash,
        public ?int $overridePriceIrr,
        public bool $discountCombinationAllowed,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}

    public function matched(): bool
    {
        return $this->ruleId !== null;
    }
}
