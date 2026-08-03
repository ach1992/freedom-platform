<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Domain;

use App\Shared\Domain\InvalidStateTransition;

enum ProvisioningState: string
{
    /** @requirement PRV-002 PRV-003 ARCH-003 */
    case Queued = 'queued';
    case Running = 'running';
    case UncertainRemoteResult = 'uncertain_remote_result';
    case RetryScheduled = 'retry_scheduled';
    case Succeeded = 'succeeded';
    case FailedFinal = 'failed_final';
    case NeedsReview = 'needs_review';
    case Compensating = 'compensating';
    case Compensated = 'compensated';

    public function transitionTo(self $next): self
    {
        if (! in_array($next, $this->allowedNextStates(), true)) {
            throw InvalidStateTransition::between('provisioning', $this->value, $next->value);
        }

        return $next;
    }

    /** @return list<self> */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Queued => [self::Running],
            self::Running => [self::Succeeded, self::UncertainRemoteResult, self::RetryScheduled, self::FailedFinal],
            self::UncertainRemoteResult => [self::Succeeded, self::RetryScheduled, self::NeedsReview],
            self::RetryScheduled => [self::Running, self::FailedFinal],
            self::Succeeded => [self::Compensating],
            self::Compensating => [self::Compensated, self::NeedsReview],
            self::FailedFinal => [self::NeedsReview],
            self::NeedsReview, self::Compensated => [],
        };
    }
}
