<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum AccountType: string
{
    case Customer = 'customer';
    case Agent = 'agent';
}
