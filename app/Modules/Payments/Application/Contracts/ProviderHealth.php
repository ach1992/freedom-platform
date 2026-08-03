<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

enum ProviderHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case Misconfigured = 'misconfigured';
}
