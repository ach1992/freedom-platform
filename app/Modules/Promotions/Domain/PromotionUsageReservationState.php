<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum PromotionUsageReservationState: string
{
    case Active = 'active';
    case Released = 'released';
}
