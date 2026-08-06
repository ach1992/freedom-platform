<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum CustomPlanActorType: string
{
    case Customer = 'customer';
    case Agent = 'agent';
}
