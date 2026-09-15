<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum OfferingOperationCode: string
{
    case Renew = 'renew';
    case AddData = 'add_data';
    case AddDays = 'add_days';
    case AddDataDays = 'add_data_days';
    case ResetUsage = 'reset_usage';
    case ChangePlan = 'change_plan';
}
