<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Domain;

enum AdministratorStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Active => in_array($next, [self::Suspended, self::Revoked], true),
            self::Suspended => in_array($next, [self::Active, self::Revoked], true),
            self::Revoked => false,
        };
    }
}
