<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Domain;

enum SensitiveApprovalState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return $this === self::Pending
            && in_array($next, [self::Approved, self::Rejected, self::Expired, self::Cancelled], true);
    }
}
