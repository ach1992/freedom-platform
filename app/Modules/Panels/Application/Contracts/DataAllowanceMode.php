<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

enum DataAllowanceMode: string
{
    case Set = 'set';
    case Add = 'add';
}
