<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum PromotionDiscountType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
