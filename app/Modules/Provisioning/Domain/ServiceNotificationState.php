<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceNotificationState: string
{
    case Triggered = 'triggered';
    case Notified = 'notified';
    case Acknowledged = 'acknowledged';
    case Escalated = 'escalated';
    case Expired = 'expired';
}
