<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Modules\Orders\Domain\OrderState;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Domain\InvalidStateTransition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkflowStateTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     OrderState|PaymentIntentState|ProvisioningState,
     *     OrderState|PaymentIntentState|ProvisioningState
     * }>
     */
    public static function validTransitions(): iterable
    {
        yield 'order paid to provisioning queue' => [OrderState::Paid, OrderState::ProvisioningQueued];
        yield 'payment verified to captured' => [PaymentIntentState::Verifying, PaymentIntentState::Captured];
        yield 'uncertain provisioning to retry' => [ProvisioningState::UncertainRemoteResult, ProvisioningState::RetryScheduled];
        yield 'final provisioning failure to review' => [ProvisioningState::FailedFinal, ProvisioningState::NeedsReview];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transitions_are_explicit(
        OrderState|PaymentIntentState|ProvisioningState $from,
        OrderState|PaymentIntentState|ProvisioningState $to,
    ): void {
        $result = match (true) {
            $from instanceof OrderState && $to instanceof OrderState => $from->transitionTo($to),
            $from instanceof PaymentIntentState && $to instanceof PaymentIntentState => $from->transitionTo($to),
            $from instanceof ProvisioningState && $to instanceof ProvisioningState => $from->transitionTo($to),
            default => self::fail('Mismatched workflow state types.'),
        };

        self::assertSame($to, $result);
    }

    public function test_paid_order_cannot_be_canceled_without_refund_workflow(): void
    {
        $this->expectException(InvalidStateTransition::class);

        OrderState::Paid->transitionTo(OrderState::Canceled);
    }

    public function test_user_return_cannot_capture_a_new_payment_directly(): void
    {
        $this->expectException(InvalidStateTransition::class);

        PaymentIntentState::Created->transitionTo(PaymentIntentState::Captured);
    }

    public function test_refund_cannot_race_with_running_provisioning(): void
    {
        $this->expectException(InvalidStateTransition::class);

        OrderState::Provisioning->transitionTo(OrderState::RefundPending);
    }
}
