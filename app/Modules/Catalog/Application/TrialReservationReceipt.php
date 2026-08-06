<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class TrialReservationReceipt
{
    public function __construct(
        public int $reservationId,
        public int $offeringId,
        public int $policyId,
        public int $policyVersion,
        public int $userId,
        public ?int $phoneNumberId,
        public string $state,
        public int $version,
        public string $capacityDate,
        public int $dailyCapacityAvailable,
        public int $routeSelectionId,
        public int $routeId,
        public int $salesServerId,
        public int $serviceTargetId,
        public int $protocolProfileId,
        public int $targetCapacityReservationId,
        public string $targetCapacityReservationKey,
        public bool $fallbackUsed,
        public ?string $disclosureFa,
        public int $dataBytes,
        public int $durationDays,
        public string $deliveryTemplateKey,
        public bool $replayed = false,
    ) {}
}
