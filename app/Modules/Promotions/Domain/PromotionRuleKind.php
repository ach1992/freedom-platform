<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum PromotionRuleKind: string
{
    case Promotion = 'promotion';
    case Referral = 'referral';
}
