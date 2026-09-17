<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\SupportTicketState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class SupportTicketRatingService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement SUP-002 SEC-002 DAT-003 QUA-004 */
    public function rate(int $ticketId, int $requesterUserId, int $score): SupportTicketRatingReceipt
    {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);
        $this->assertScore($score);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $requesterUserId, $score): SupportTicketRatingReceipt {
            /** @var object{id:int|string,state:string}|null $ticket */
            $ticket = $connection->table('support_tickets')
                ->where('id', $ticketId)
                ->where('requester_user_id', $requesterUserId)
                ->lockForUpdate()
                ->first(['id', 'state']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket is unavailable for this customer.');
            }

            $existing = $connection->table('support_ticket_ratings')
                ->where('ticket_id', $ticketId)
                ->first($this->snapshotColumns());
            if ($existing instanceof stdClass) {
                $rating = $this->snapshotFromRow($existing);
                if ($rating->requesterUserId !== $requesterUserId || $rating->score !== $score) {
                    throw new DomainException('Support ticket rating is already recorded.');
                }

                return new SupportTicketRatingReceipt($rating, true);
            }

            if (SupportTicketState::from((string) $ticket->state) !== SupportTicketState::Closed) {
                throw new DomainException('Support ticket must be closed before rating.');
            }

            $ratingId = (int) $connection->table('support_ticket_ratings')->insertGetId([
                'ticket_id' => $ticketId,
                'requester_user_id' => $requesterUserId,
                'score' => $score,
                'created_at' => $this->timestamp(),
            ]);

            $row = $connection->table('support_ticket_ratings')
                ->where('id', $ratingId)
                ->first($this->snapshotColumns());
            if (! $row instanceof stdClass) {
                throw new RuntimeException('Support ticket rating disappeared after creation.');
            }

            return new SupportTicketRatingReceipt($this->snapshotFromRow($row), false);
        }, 3);
    }

    /** @requirement SUP-002 SEC-002 */
    public function ratingForCustomer(int $ticketId, int $requesterUserId): ?SupportTicketRatingSnapshot
    {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);

        $connection = $this->database->connection();
        if (! $connection->table('support_tickets')
            ->where('id', $ticketId)
            ->where('requester_user_id', $requesterUserId)
            ->exists()) {
            throw new RuntimeException('Support ticket is unavailable for this customer.');
        }

        $row = $connection->table('support_ticket_ratings')
            ->where('ticket_id', $ticketId)
            ->where('requester_user_id', $requesterUserId)
            ->first($this->snapshotColumns());

        return $row instanceof stdClass ? $this->snapshotFromRow($row) : null;
    }

    /** @return list<string> */
    private function snapshotColumns(): array
    {
        return ['id', 'ticket_id', 'requester_user_id', 'score', 'created_at'];
    }

    private function snapshotFromRow(stdClass $row): SupportTicketRatingSnapshot
    {
        return new SupportTicketRatingSnapshot(
            (int) $row->id,
            (int) $row->ticket_id,
            (int) $row->requester_user_id,
            (int) $row->score,
            (string) $row->created_at,
        );
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Support ticket rating identity must be positive.');
        }
    }

    private function assertScore(int $score): void
    {
        if ($score < 1 || $score > 5) {
            throw new InvalidArgumentException('Support ticket rating score must be between 1 and 5.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
