<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Panels\Domain\PanelCapabilityCode;
use InvalidArgumentException;

final readonly class OfferingOperationPolicy
{
    public ?string $requiredCapabilityCode;

    public function __construct(
        public OfferingOperationCode $operation,
        public bool $customerEnabled,
        public bool $administratorEnabled,
        public int $priceIrr,
        public bool $discountEligible,
        ?string $requiredCapabilityCode,
    ) {
        if ($priceIrr < 0) {
            throw new InvalidArgumentException('Operation price must not be negative.');
        }

        if (! $customerEnabled && ! $administratorEnabled) {
            throw new InvalidArgumentException('An operation policy must enable at least one audience.');
        }

        $this->requiredCapabilityCode = $requiredCapabilityCode === null
            ? null
            : PanelCapabilityCode::fromInput($requiredCapabilityCode)->value;
    }
}
