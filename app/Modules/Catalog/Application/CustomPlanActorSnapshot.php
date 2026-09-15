<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CustomPlanActorType;

final readonly class CustomPlanActorSnapshot
{
    /** @param list<int> $tagIds */
    public function __construct(
        public int $userId,
        public CustomPlanActorType $actorType,
        public ?int $telegramUserId,
        public ?string $tierCode,
        public array $tagIds,
        public string $eligibilityHash,
    ) {}
}
