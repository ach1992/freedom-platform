<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum PlanOfferingRouteType: string
{
    case Primary = 'primary';
    case Fallback = 'fallback';
}
