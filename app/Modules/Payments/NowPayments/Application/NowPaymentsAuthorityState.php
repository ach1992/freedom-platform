<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application;

use App\Shared\Domain\InvalidStateTransition;

enum NowPaymentsAuthorityState: string
{
    case Initiating = 'initiating';
    case Created = 'created';
    case Uncertain = 'uncertain';
    case ManualReview = 'manual_review';
    case Finished = 'finished';
    case Failed = 'failed';
    case Expired = 'expired';

    public function transitionTo(self $next): self
    {
        if ($next === $this) {
            return $this;
        }
        if (! in_array($next, $this->allowedNextStates(), true)) {
            throw InvalidStateTransition::between('NOWPayments authority', $this->value, $next->value);
        }

        return $next;
    }

    /** @return list<self> */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Initiating => [self::Created, self::Uncertain, self::Failed],
            self::Uncertain => [self::ManualReview],
            self::Created => [self::ManualReview, self::Finished, self::Failed, self::Expired],
            self::ManualReview => [self::Finished, self::Failed, self::Expired],
            self::Finished, self::Failed, self::Expired => [],
        };
    }
}
