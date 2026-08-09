<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

enum AgentPricingAction: string
{
    case Purchase = 'purchase';
    case Renew = 'renew';
    case AddData = 'add_data';
    case AddDays = 'add_days';
}
