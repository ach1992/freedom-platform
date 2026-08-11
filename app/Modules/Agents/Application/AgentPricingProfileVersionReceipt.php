<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\Agents\Domain\AgentPricingState;

final readonly class AgentPricingProfileVersionReceipt
{
    public function __construct(
        public int $profileId,
        public string $profilePublicId,
        public string $profileCode,
        public int $versionId,
        public int $version,
        public AgentPricingState $state,
        public bool $discountCombinationAllowed,
        public string $configurationHash,
        public bool $replayed,
    ) {}
}
