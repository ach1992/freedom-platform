<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderSourceType;

final readonly class OrderSourceAuthorizationReceipt
{
    public function __construct(
        public int $authorizationId,
        public string $publicId,
        public OrderSourceType $sourceType,
        public int $userId,
        public int $planOfferingId,
        public string $authorizationKey,
        public string $configurationSnapshotHash,
        public string $actorType,
        public ?int $actorId,
        public string $reasonCode,
        public string $correlationId,
        public bool $replayed = false,
    ) {}
}
