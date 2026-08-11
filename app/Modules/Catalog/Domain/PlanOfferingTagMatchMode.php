<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum PlanOfferingTagMatchMode: string
{
    case Any = 'any';
    case All = 'all';
}
