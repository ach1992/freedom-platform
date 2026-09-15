<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CustomPlanActorType;
use InvalidArgumentException;

final readonly class CustomPlanRequest
{
    public ?string $requestedUsername;

    public function __construct(
        public int $offeringId,
        public int $userId,
        public CustomPlanActorType $actorType,
        public int $dataGb,
        public int $days,
        ?string $requestedUsername,
    ) {
        if ($offeringId < 1 || $userId < 1 || $dataGb < 1 || $days < 1) {
            throw new InvalidArgumentException('Custom-plan identifiers, data and days must be positive.');
        }
        $this->requestedUsername = $requestedUsername === null ? null : strtolower(trim($requestedUsername));
    }

    /** @return array<string, int|string|null> */
    public function payload(): array
    {
        return [
            'offering_id' => $this->offeringId,
            'user_id' => $this->userId,
            'actor_type' => $this->actorType->value,
            'data_gb' => $this->dataGb,
            'days' => $this->days,
            'requested_username' => $this->requestedUsername,
        ];
    }
}
