<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceNotificationRunReceipt
{
    public function __construct(
        public int $candidates,
        public int $triggered,
        public int $queued,
        public int $notified,
        public int $escalated,
        public int $expired,
        public int $skipped,
    ) {}

    public function requiresAttention(): bool
    {
        return $this->escalated > 0;
    }
}
