<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

enum AgentApplicationState: string
{
    case Submitted = 'submitted';
    case Claimed = 'claimed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Submitted => in_array($next, [self::Claimed, self::Rejected, self::Withdrawn], true),
            self::Claimed => in_array($next, [self::Submitted, self::Approved, self::Rejected], true),
            self::Rejected => $next === self::Submitted,
            self::Approved, self::Withdrawn => false,
        };
    }

    public function keepsActiveApplicationSlot(): bool
    {
        return in_array($this, [self::Submitted, self::Claimed], true);
    }
}
