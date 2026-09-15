<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TrialReservationRequest
{
    public function __construct(
        public int $offeringId,
        public int $userId,
        public ?int $requestedRouteId,
        public ?int $requestedProtocolProfileId,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($offeringId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Trial offering and user IDs must be positive.');
        }
        if ($requestedRouteId !== null && $requestedRouteId < 1) {
            throw new InvalidArgumentException('Requested trial route ID must be positive.');
        }
        if ($requestedProtocolProfileId !== null && $requestedProtocolProfileId < 1) {
            throw new InvalidArgumentException('Requested trial protocol profile ID must be positive.');
        }
    }

    /** @return array<string, int|string|null> */
    public function payload(): array
    {
        return [
            'offering_id' => $this->offeringId,
            'user_id' => $this->userId,
            'requested_route_id' => $this->requestedRouteId,
            'requested_protocol_profile_id' => $this->requestedProtocolProfileId,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}
