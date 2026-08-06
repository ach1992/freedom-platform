<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum CustomPlanTagMatchMode: string
{
    case Any = 'any';
    case All = 'all';
}
