<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

enum AgentPricingState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
