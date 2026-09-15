<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use DomainException;

enum PanelResourceState: string
{
    case Disabled = 'disabled';
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Archived = 'archived';

    public function assertCanTransitionTo(self $target): void
    {
        $allowed = match ($this) {
            self::Disabled => [self::Active, self::Archived],
            self::Active => [self::Maintenance, self::Disabled],
            self::Maintenance => [self::Active, self::Disabled],
            self::Archived => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Panel resource transition {$this->value} -> {$target->value} is not allowed.");
        }
    }
}
