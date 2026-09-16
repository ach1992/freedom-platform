<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Support;

use App\Modules\Support\Domain\SupportTicketState;
use App\Modules\Support\Domain\SupportTicketTransitionPolicy;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

/** @requirement SUP-001 QUA-004 */
final class SupportTicketTransitionPolicyTest extends TestCase
{
    public function test_complete_transition_matrix_is_pinned(): void
    {
        $policy = new SupportTicketTransitionPolicy;
        $now = new DateTimeImmutable('2026-09-16T00:00:00+00:00');
        $allowed = [
            SupportTicketState::New->value => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::AwaitingCustomer,
                SupportTicketState::Investigating,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::AwaitingSupport->value => [
                SupportTicketState::AwaitingCustomer,
                SupportTicketState::Investigating,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::AwaitingCustomer->value => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::Investigating,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::Investigating->value => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::AwaitingCustomer,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::Resolved->value => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::Closed,
            ],
            SupportTicketState::Closed->value => [
                SupportTicketState::AwaitingSupport,
            ],
        ];

        foreach (SupportTicketState::cases() as $from) {
            self::assertSame(
                $allowed[$from->value],
                $policy->allowedTargets($from, $now, $now->modify('+1 hour')),
                "Allowed target projection drifted for {$from->value}",
            );
            foreach (SupportTicketState::cases() as $to) {
                $expectedAllowed = in_array($to, $allowed[$from->value], true);
                try {
                    $policy->assertAllowed($from, $to, $now, $now->modify('+1 hour'));
                    self::assertTrue($expectedAllowed, "Unexpectedly allowed {$from->value} -> {$to->value}");
                } catch (DomainException) {
                    self::assertFalse($expectedAllowed, "Unexpectedly rejected {$from->value} -> {$to->value}");
                }
            }
        }
    }

    public function test_closed_reopen_window_is_inclusive_at_exact_deadline(): void
    {
        $policy = new SupportTicketTransitionPolicy;
        $deadline = new DateTimeImmutable('2026-09-19T00:00:00.000000+00:00');

        $policy->assertAllowed(
            SupportTicketState::Closed,
            SupportTicketState::AwaitingSupport,
            $deadline->modify('-1 microsecond'),
            $deadline,
        );
        $policy->assertAllowed(
            SupportTicketState::Closed,
            SupportTicketState::AwaitingSupport,
            $deadline,
            $deadline,
        );

        try {
            $policy->assertAllowed(
                SupportTicketState::Closed,
                SupportTicketState::AwaitingSupport,
                $deadline->modify('+1 microsecond'),
                $deadline,
            );
            self::fail('Reopen must fail immediately after the inclusive deadline.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        self::assertSame(
            [SupportTicketState::AwaitingSupport],
            $policy->allowedTargets(SupportTicketState::Closed, $deadline, $deadline),
        );
        self::assertSame(
            [],
            $policy->allowedTargets(SupportTicketState::Closed, $deadline->modify('+1 microsecond'), $deadline),
        );
    }
}
