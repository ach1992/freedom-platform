<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class TargetCapacityRecord
{
    public function __construct(
        public int $id,
        public int $serviceTargetId,
        public int $hardLimit,
        public int $heldUnits,
        public int $committedUnits,
        public string $state,
        public int $version,
    ) {}

    public function availableUnits(): int
    {
        return max(0, $this->hardLimit - $this->heldUnits - $this->committedUnits);
    }
}
