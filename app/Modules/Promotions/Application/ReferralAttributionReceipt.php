<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

final readonly class ReferralAttributionReceipt
{
    public function __construct(
        public int $relationshipId,
        public int $referredUserId,
        public int $inviterUserId,
        public string $inviterToken,
        public bool $locked,
        public bool $replayed,
    ) {}
}
