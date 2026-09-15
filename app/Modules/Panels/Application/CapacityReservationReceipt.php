<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class CapacityReservationReceipt
{
    public function __construct(
        public int $reservationId,
        public string $reservationKey,
        public string $state,
        public int $units,
        public int $reservationVersion,
        public CapacityAvailability $availability,
        public bool $replayed,
    ) {}
}
