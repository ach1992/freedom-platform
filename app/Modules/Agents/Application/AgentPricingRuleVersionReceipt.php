<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\Agents\Domain\AgentPricingState;

final readonly class AgentPricingRuleVersionReceipt
{
    public function __construct(
        public int $ruleId,
        public string $rulePublicId,
        public string $profileCode,
        public string $ruleCode,
        public int $versionId,
        public int $version,
        public AgentPricingState $state,
        public int $overridePriceIrr,
        public int $specificity,
        public string $configurationHash,
        public bool $replayed,
    ) {}
}
