<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceSyncScope;

final readonly class ServiceSyncRunReceipt
{
    public function __construct(
        public int $runId,
        public string $runPublicId,
        public ServiceSyncScope $scope,
        public int $candidates,
        public int $processed,
        public int $anomalies,
        public int $failures,
        public int $skipped,
    ) {}

    public function requiresAttention(): bool
    {
        return $this->anomalies > 0 || $this->failures > 0;
    }
}
