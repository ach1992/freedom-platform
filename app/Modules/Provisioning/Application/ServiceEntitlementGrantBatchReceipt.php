<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceEntitlementGrantBatchReceipt
{
    public function __construct(
        public string $batchPublicId,
        public string $state,
        public string $selectionMode,
        public ?int $selectedSalesServerId,
        public int $itemCount,
        public int $pendingCount,
        public int $queuedCount,
        public int $succeededCount,
        public int $failedCount,
        public int $needsReviewCount,
        public int $cancelledCount,
        public ?int $dataBytes,
        public ?int $durationDays,
        public bool $notifyCustomers,
        public string $expiresAt,
        public bool $replayed = false,
    ) {}

    public function requiresAttention(): bool
    {
        return $this->failedCount > 0 || $this->needsReviewCount > 0;
    }
}
