<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class RouteSelectionReceipt
{
    public function __construct(
        public int $selectionId,
        public int $offeringId,
        public int $routePolicyId,
        public int $routeId,
        public int $salesServerId,
        public int $serviceTargetId,
        public int $protocolProfileId,
        public int $capacityReservationId,
        public string $capacityReservationKey,
        public int $units,
        public bool $fallbackUsed,
        public ?string $disclosureFa,
        public int $capacityAvailableUnits,
        public int $capacityVersion,
        public bool $replayed = false,
    ) {}
}
