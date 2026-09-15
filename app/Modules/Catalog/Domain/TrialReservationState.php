<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use DomainException;

enum TrialReservationState: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';

    public function assertCanTransitionTo(self $target): void
    {
        $allowed = $this === self::Reserved
            && in_array($target, [self::Committed, self::Released, self::Expired], true);

        if (! $allowed) {
            throw new DomainException('Trial reservation transition is not allowed.');
        }
    }
}
