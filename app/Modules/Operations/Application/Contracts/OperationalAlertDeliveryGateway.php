<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface OperationalAlertDeliveryGateway
{
    public function queue(
        string $audience,
        string $severity,
        string $eventName,
        int $occurrenceCount,
        bool $resolved,
        string $trackingCode,
        string $requestKey,
        string $correlationId,
    ): string;
}
