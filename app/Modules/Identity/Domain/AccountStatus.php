<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum AccountStatus: string
{
    case Active = 'active';
    case Limited = 'limited';
    case Suspended = 'suspended';
    case Blocked = 'blocked';
}
