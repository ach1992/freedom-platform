<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\RouteSelectionActor;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RouteSelectionRequest
{
    public function __construct(
        public int $offeringId,
        public int $userId,
        public RouteSelectionActor $actor,
        public ?int $requestedRouteId,
        public ?int $requestedProtocolProfileId,
        public int $units,
        public DateTimeImmutable $expiresAt,
    ) {
        foreach ([$offeringId, $userId, $units] as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Route selection identifiers and units must be positive.');
            }
        }
        if ($requestedRouteId !== null && $requestedRouteId < 1) {
            throw new InvalidArgumentException('Requested route ID must be positive.');
        }
        if ($requestedProtocolProfileId !== null && $requestedProtocolProfileId < 1) {
            throw new InvalidArgumentException('Requested protocol profile ID must be positive.');
        }
    }

    /** @return array<string, int|string|null> */
    public function payload(): array
    {
        return [
            'offering_id' => $this->offeringId,
            'user_id' => $this->userId,
            'actor' => $this->actor->value,
            'requested_route_id' => $this->requestedRouteId,
            'requested_protocol_profile_id' => $this->requestedProtocolProfileId,
            'units' => $this->units,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
        ];
    }
}
