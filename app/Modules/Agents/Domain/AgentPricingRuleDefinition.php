<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

use InvalidArgumentException;

final readonly class AgentPricingRuleDefinition
{
    public function __construct(
        public AgentPricingState $state,
        public int $overridePriceIrr,
        public ?AgentPricingAction $action = null,
        public ?int $planOfferingId = null,
        public ?int $salesServerId = null,
        public ?int $productId = null,
    ) {
        if ($overridePriceIrr < 0) {
            throw new InvalidArgumentException('Agent pricing override must be non-negative integer IRR.');
        }

        foreach ([
            'plan offering ID' => $planOfferingId,
            'sales server ID' => $salesServerId,
            'product ID' => $productId,
        ] as $label => $id) {
            if ($id !== null && $id < 1) {
                throw new InvalidArgumentException("Agent pricing {$label} must be positive.");
            }
        }
    }

    public function specificity(): int
    {
        return ($this->action === null ? 0 : 1)
            + ($this->planOfferingId === null ? 0 : 1)
            + ($this->salesServerId === null ? 0 : 1)
            + ($this->productId === null ? 0 : 1);
    }

    /** @return array<string, int|string|null> */
    public function snapshot(): array
    {
        $snapshot = [
            'action' => $this->action?->value,
            'override_price_irr' => $this->overridePriceIrr,
            'plan_offering_id' => $this->planOfferingId,
            'product_id' => $this->productId,
            'sales_server_id' => $this->salesServerId,
            'specificity' => $this->specificity(),
            'state' => $this->state->value,
        ];
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}
