<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum PlanOfferingAudience: string
{
    case Customers = 'customers';
    case Agents = 'agents';
    case Both = 'both';
}
