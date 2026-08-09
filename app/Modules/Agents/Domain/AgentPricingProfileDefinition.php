<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

final readonly class AgentPricingProfileDefinition
{
    public function __construct(
        public AgentPricingState $state,
        public bool $discountCombinationAllowed,
    ) {}

    /** @return array<string, bool|string> */
    public function snapshot(): array
    {
        $snapshot = [
            'discount_combination_allowed' => $this->discountCombinationAllowed,
            'state' => $this->state->value,
        ];
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}
