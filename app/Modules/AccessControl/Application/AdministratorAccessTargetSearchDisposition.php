<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

enum AdministratorAccessTargetSearchDisposition: string
{
    case Matched = 'matched';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
}
