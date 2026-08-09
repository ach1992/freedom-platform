<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

final readonly class PromotionUsageReleaseReceipt
{
    public function __construct(
        public int $releaseId,
        public string $releasePublicId,
        public string $releaseKey,
        public string $reservationPublicId,
        public int $userId,
        public bool $replayed,
    ) {}
}
