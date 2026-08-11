<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use DomainException;

enum PanelConnectionState: string
{
    case Disabled = 'disabled';
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Archived = 'archived';

    /** @requirement PRV-001 SEC-001 */
    public function assertCanTransitionTo(self $target): void
    {
        $allowed = match ($this) {
            self::Disabled => in_array($target, [self::Active, self::Archived], true),
            self::Active => in_array($target, [self::Disabled, self::Maintenance], true),
            self::Maintenance => in_array($target, [self::Disabled, self::Active], true),
            self::Archived => false,
        };

        if (! $allowed) {
            throw new DomainException('Panel connection state transition is not allowed.');
        }
    }
}
