<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum PlanOfferingProtocolSelectionMode: string
{
    case Fixed = 'fixed';
    case Customer = 'customer_selects';
    case System = 'system_selects';
}
