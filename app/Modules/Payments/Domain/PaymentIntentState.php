<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\InvalidStateTransition;

enum PaymentIntentState: string
{
    /** @requirement PAY-002 ARCH-003 */
    case Created = 'created';
    case AwaitingUserAction = 'awaiting_user_action';
    case Submitted = 'submitted';
    case Verifying = 'verifying';
    case PendingManualReview = 'pending_manual_review';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function transitionTo(self $next): self
    {
        if (! in_array($next, $this->allowedNextStates(), true)) {
            throw InvalidStateTransition::between('payment intent', $this->value, $next->value);
        }

        return $next;
    }

    /** @return list<self> */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Created => [self::AwaitingUserAction, self::Canceled],
            self::AwaitingUserAction => [self::Submitted, self::Expired],
            self::Submitted => [self::Verifying, self::Failed],
            self::Verifying => [self::PendingManualReview, self::Authorized, self::Captured, self::Failed],
            self::PendingManualReview => [self::Verifying, self::Captured, self::Failed],
            self::Authorized => [self::Captured],
            self::Captured => [self::RefundPending],
            self::RefundPending => [self::Refunded, self::PartiallyRefunded],
            self::PartiallyRefunded => [self::RefundPending, self::Refunded],
            self::Failed, self::Expired, self::Canceled, self::Refunded => [],
        };
    }
}
