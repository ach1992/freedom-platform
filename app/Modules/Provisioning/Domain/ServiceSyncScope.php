<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

enum ServiceSyncScope: string
{
    case Service = 'service';
    case Batch = 'batch';
    case Full = 'full';
}
