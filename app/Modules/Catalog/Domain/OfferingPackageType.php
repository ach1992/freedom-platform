<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum OfferingPackageType: string
{
    case Renewal = 'renewal';
    case AddData = 'add_data';
    case AddDays = 'add_days';
    case AddDataDays = 'add_data_days';
}
