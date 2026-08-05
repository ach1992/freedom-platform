<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Domain;

enum OwnerTransferState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return $this === self::Pending
            && in_array($next, [self::Accepted, self::Expired, self::Cancelled], true);
    }
}
