<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceBatchGrantReceipt
{
    public function __construct(
        public int $batchId,
        public string $batchPublicId,
        public string $state,
        public int $itemCount,
        public int $succeededCount,
        public int $failedCount,
        public bool $replayed = false,
    ) {}
}
