<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

final readonly class WalletMaintenanceResult
{
    public function __construct(
        public ExpiredWalletHoldCleanupResult $cleanup,
        public int $walletsExamined,
        public int $walletsReconciled,
        public int $initialSnapshots,
        public int $matchedSnapshots,
        public int $refreshedSnapshots,
        public int $walletReviewCount,
    ) {}

    public function requiresReview(): bool
    {
        return $this->cleanup->requiresReview() || $this->walletReviewCount > 0;
    }
}
