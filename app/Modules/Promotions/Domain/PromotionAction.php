<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum PromotionAction: string
{
    case Purchase = 'purchase';
    case Renew = 'renew';
    case AddData = 'add_data';
    case AddDays = 'add_days';
    case AddDataDays = 'add_data_days';
}
