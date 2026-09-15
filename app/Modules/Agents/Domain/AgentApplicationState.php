<?php

declare(strict_types=1);

namespace App\Modules\Agents\Domain;

enum AgentApplicationState: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Submitted => in_array($target, [self::UnderReview, self::Withdrawn], true),
            self::UnderReview => in_array($target, [self::Submitted, self::Approved, self::Rejected], true),
            self::Approved, self::Rejected, self::Withdrawn => false,
        };
    }

    public function keepsActiveApplicationSlot(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview], true);
    }
}
