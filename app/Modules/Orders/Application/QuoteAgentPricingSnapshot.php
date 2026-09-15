<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentPricingAction;

/** @requirement AGT-005 BUY-002 DAT-004 QUA-001 */
final readonly class QuoteAgentPricingSnapshot
{
    public function __construct(
        public int $resolutionId,
        public string $resolutionPublicId,
        public string $resolutionConfigurationHash,
        public int $agentProfileId,
        public int $pricingProfileId,
        public string $pricingProfilePublicId,
        public string $pricingProfileCode,
        public int $pricingProfileVersion,
        public string $pricingProfileConfigurationHash,
        public AgentPricingAction $action,
        public ?int $ruleId,
        public ?string $rulePublicId,
        public ?string $ruleCode,
        public ?int $ruleVersion,
        public ?string $ruleConfigurationHash,
        public bool $discountCombinationAllowed,
    ) {}

    public function matched(): bool
    {
        return $this->ruleId !== null;
    }
}
