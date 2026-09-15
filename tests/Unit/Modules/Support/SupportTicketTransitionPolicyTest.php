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
    public function test_closed_ticket_can_reopen_only_inside_window(): void
    {
        $policy = new SupportTicketTransitionPolicy;
        $now = new DateTimeImmutable('2026-09-16T00:00:00+00:00');

        $policy->assertAllowed(
            SupportTicketState::Closed,
            SupportTicketState::AwaitingSupport,
            $now,
            $now->modify('+1 minute'),
        );

        $this->expectException(DomainException::class);
        $policy->assertAllowed(
            SupportTicketState::Closed,
            SupportTicketState::AwaitingSupport,
            $now,
            $now->modify('-1 microsecond'),
        );
    }

    public function test_invalid_terminal_transition_fails_closed(): void
    {
        $this->expectException(DomainException::class);
        (new SupportTicketTransitionPolicy)->assertAllowed(
            SupportTicketState::Closed,
            SupportTicketState::Resolved,
            new DateTimeImmutable('2026-09-16T00:00:00+00:00'),
            new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        );
    }
}
