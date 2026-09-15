<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Domain;

enum BenefitCodeState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
