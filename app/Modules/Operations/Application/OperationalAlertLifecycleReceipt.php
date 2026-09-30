<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class OperationalAlertLifecycleReceipt
{
    public function __construct(
        public string $alertId,
        public string $eventType,
        public bool $replayed,
    ) {}
}
