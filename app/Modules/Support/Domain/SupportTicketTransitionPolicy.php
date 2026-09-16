<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain;

use DateTimeImmutable;
use DomainException;

final readonly class SupportTicketTransitionPolicy
{
    /** @requirement SUP-001 */
    public function assertAllowed(
        SupportTicketState $from,
        SupportTicketState $to,
        DateTimeImmutable $now,
        ?DateTimeImmutable $reopenUntil,
    ): void {
        if ($from === $to) {
            throw new DomainException('Support ticket state transition must change state.');
        }

        if ($from === SupportTicketState::Closed) {
            if ($to !== SupportTicketState::AwaitingSupport) {
                throw new DomainException('Closed support ticket can only be reopened to awaiting support.');
            }
            if ($reopenUntil === null || $now > $reopenUntil) {
                throw new DomainException('Support ticket reopen window has expired.');
            }

            return;
        }

        $allowed = match ($from) {
            SupportTicketState::New => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::AwaitingCustomer,
                SupportTicketState::Investigating,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::AwaitingSupport => [
                SupportTicketState::AwaitingCustomer,
                SupportTicketState::Investigating,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::AwaitingCustomer => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::Investigating,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::Investigating => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::AwaitingCustomer,
                SupportTicketState::Resolved,
                SupportTicketState::Closed,
            ],
            SupportTicketState::Resolved => [
                SupportTicketState::AwaitingSupport,
                SupportTicketState::Closed,
            ],
        };

        if (! in_array($to, $allowed, true)) {
            throw new DomainException('Support ticket state transition is invalid.');
        }
    }
}
