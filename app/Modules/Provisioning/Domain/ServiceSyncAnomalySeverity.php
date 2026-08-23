<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceSyncAnomalySeverity: string
{
    case Informational = 'informational';
    case Warning = 'warning';
    case Critical = 'critical';
}
