<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

enum PromotionRuleState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
