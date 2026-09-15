<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

final readonly class ExpiredWalletHoldCleanupResult
{
    /** @param list<int> $reviewHoldIds */
    public function __construct(
        public int $examined,
        public int $released,
        public int $replayed,
        public array $reviewHoldIds,
    ) {}

    public function requiresReview(): bool
    {
        return $this->reviewHoldIds !== [];
    }
}
