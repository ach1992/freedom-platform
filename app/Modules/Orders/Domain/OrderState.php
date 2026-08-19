<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

use App\Shared\Domain\InvalidStateTransition;

enum OrderState: string
{
    /** @requirement PAY-002 ARCH-003 */
    case Draft = 'draft';
    case Quoted = 'quoted';
    case AwaitingPayment = 'awaiting_payment';
    case PaymentPendingReview = 'payment_pending_review';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case ProvisioningQueued = 'provisioning_queued';
    case Provisioning = 'provisioning';
    case Completed = 'completed';
    case NeedsReview = 'needs_review';
    case Canceled = 'canceled';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function transitionTo(self $next): self
    {
        if (! in_array($next, $this->allowedNextStates(), true)) {
            throw InvalidStateTransition::between('order', $this->value, $next->value);
        }

        return $next;
    }

    /** @return list<self> */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Quoted, self::Canceled],
            self::Quoted => [self::AwaitingPayment, self::Canceled],
            self::AwaitingPayment => [self::PaymentPendingReview, self::Paid, self::Canceled],
            self::PaymentPendingReview => [self::Paid, self::Canceled],
            self::Authorized => [self::ProvisioningQueued, self::Canceled],
            self::Paid => [self::ProvisioningQueued, self::RefundPending],
            self::ProvisioningQueued => [self::Provisioning, self::NeedsReview],
            self::Provisioning => [self::Completed, self::NeedsReview],
            self::Completed => [self::RefundPending],
            self::NeedsReview => [self::ProvisioningQueued, self::RefundPending],
            self::RefundPending => [self::Refunded, self::PartiallyRefunded],
            self::PartiallyRefunded => [self::RefundPending, self::Refunded],
            self::Canceled, self::Refunded => [],
        };
    }
}
