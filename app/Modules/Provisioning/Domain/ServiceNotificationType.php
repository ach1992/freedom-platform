<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceNotificationType: string
{
    case Expiry = 'expiry';
    case Usage = 'usage';
    case LowBalance = 'low_balance';
    case RenewalFailure = 'renewal_failure';
    case ServiceState = 'service_state';
    case SyncIssue = 'sync_issue';
}
