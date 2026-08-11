<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class CapacityAvailability
{
    public function __construct(
        public int $capacityId,
        public int $serviceTargetId,
        public int $hardLimit,
        public int $heldUnits,
        public int $committedUnits,
        public int $availableUnits,
        public bool $acceptingReservations,
        public int $version,
    ) {}
}
