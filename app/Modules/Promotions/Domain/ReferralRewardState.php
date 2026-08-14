<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum ReferralRewardState: string
{
    case Pending = 'pending';
    case Released = 'released';
    case Canceled = 'canceled';
    case Reversed = 'reversed';
}
