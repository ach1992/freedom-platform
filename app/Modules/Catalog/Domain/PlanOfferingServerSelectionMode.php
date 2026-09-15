<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum PlanOfferingServerSelectionMode: string
{
    case Customer = 'customer_selects';
    case System = 'system_selects';
    case Hybrid = 'hybrid';
}
