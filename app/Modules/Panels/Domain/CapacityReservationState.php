<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use DomainException;

enum CapacityReservationState: string
{
    case Held = 'held';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';

    public function assertCanTransitionTo(self $target): void
    {
        $allowed = match ($this) {
            self::Held => [self::Committed, self::Released, self::Expired],
            self::Committed => [self::Released],
            self::Released, self::Expired => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new DomainException("Capacity reservation transition {$this->value} -> {$target->value} is not allowed.");
        }
    }
}
