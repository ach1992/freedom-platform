<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceNotificationType: string
{
    case Expiry = 'expiry';
    case LowBalance = 'low_balance';
    case RenewalFailure = 'renewal_failure';
}
