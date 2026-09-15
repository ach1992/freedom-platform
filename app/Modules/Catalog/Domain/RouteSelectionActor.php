<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum RouteSelectionActor: string
{
    case Customer = 'customer';
    case Agent = 'agent';
}
