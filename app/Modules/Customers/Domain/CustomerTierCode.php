<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain;

enum CustomerTierCode: string
{
    case New = 'new';
    case Normal = 'normal';
    case Loyal = 'loyal';
    case Vip = 'vip';
}
