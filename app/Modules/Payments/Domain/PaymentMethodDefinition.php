<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use InvalidArgumentException;

final readonly class PaymentMethodDefinition
{
    public function __construct(
        public PaymentConfigurationState $state,
        public int $displayPriority,
        public ?int $minimumAmountIrr = null,
        public ?int $maximumAmountIrr = null,
        public bool $allowDegradedHealth = false,
    ) {
        if ($displayPriority < 0 || $displayPriority > 65535) {
            throw new InvalidArgumentException('Payment method display priority must be between 0 and 65535.');
        }
        if ($minimumAmountIrr !== null && $minimumAmountIrr < 0) {
            throw new InvalidArgumentException('Payment method minimum amount must be non-negative integer IRR.');
        }
        if ($maximumAmountIrr !== null && $maximumAmountIrr < 0) {
            throw new InvalidArgumentException('Payment method maximum amount must be non-negative integer IRR.');
        }
        if ($minimumAmountIrr !== null && $maximumAmountIrr !== null && $maximumAmountIrr < $minimumAmountIrr) {
            throw new InvalidArgumentException('Payment method maximum amount cannot be below minimum amount.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    public function snapshot(): array
    {
        $snapshot = [
            'allow_degraded_health' => $this->allowDegradedHealth,
            'display_priority' => $this->displayPriority,
            'maximum_amount_irr' => $this->maximumAmountIrr,
            'minimum_amount_irr' => $this->minimumAmountIrr,
            'state' => $this->state->value,
        ];
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}
