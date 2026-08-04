<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Domain;

enum PermissionEffect: string
{
    case Inherit = 'inherit';
    case Allow = 'allow';
    case Deny = 'deny';
}
