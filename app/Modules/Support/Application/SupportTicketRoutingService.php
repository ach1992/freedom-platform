<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Support\Domain\SupportTicketPriority;
use App\Modules\Support\Domain\SupportTicketState;
use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Exact, permission-gated operator routing/discovery authority for Support.
 *
 * This service intentionally exposes only allowlisted exact criteria and the
 * existing Support ticket snapshot. It does not provide a free-form customer
 * database search surface.
 */
final readonly class SupportTicketRoutingService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorUserPermissionAuthorizer $authorizer,
    ) {}

    /**
     * @return list<SupportTicketSnapshot>
     *
     * @requirement SUP-002 ACL-001 ACL-002 SEC-002 QUA-004 QUA-008
     */
    public function search(
        int $actorUserId,
        SupportTicketSearchField $field,
        string $value,
        int $limit = 20,
    ): array {
        $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);

        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Support ticket search limit is invalid.');
        }

        $normalized = trim($value);
        if ($normalized === '' || strlen($normalized) > 64) {
            throw new InvalidArgumentException('Support ticket search value is invalid.');
        }

        if ($field === SupportTicketSearchField::Tracking) {
            $normalized = strtoupper($normalized);
            if (preg_match('/\ATKT-[A-Z0-9]{16}\z/', $normalized) !== 1) {
                throw new InvalidArgumentException('Support ticket tracking search value is invalid.');
            }
            $criterion = $normalized;
        } else {
            if (preg_match('/\A[1-9][0-9]{0,18}\z/', $normalized) !== 1) {
                throw new InvalidArgumentException('Support ticket numeric search value is invalid.');
            }
            $criterion = filter_var($normalized, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($criterion)) {
                throw new InvalidArgumentException('Support ticket numeric search value is invalid.');
            }
        }

        $rows = $this->database->connection()
            ->table('support_tickets')
            ->where($field->column(), $criterion)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $tickets = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                throw new RuntimeException('Support ticket search returned an invalid row.');
            }
            $tickets[] = $this->snapshotFromRow($row);
        }

        return $tickets;
    }

    /** @requirement SUP-002 ACL-001 ACL-002 SEC-002 QUA-004 QUA-008 */
    public function assign(int $actorUserId, int $ticketId, int $assigneeUserId): SupportTicketSnapshot
    {
        if ($ticketId < 1 || $assigneeUserId < 1) {
            throw new InvalidArgumentException('Support ticket assignment identity is invalid.');
        }

        $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);
        $this->authorizer->authorizeUser($assigneeUserId, SupportTicketSupportService::PERMISSION);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $assigneeUserId): SupportTicketSnapshot {
            /** @var object{id:int|string,assigned_user_id:int|string|null,state:string}|null $ticket */
            $ticket = $connection->table('support_tickets')
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first(['id', 'assigned_user_id', 'state']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket does not exist.');
            }
            if (SupportTicketState::from((string) $ticket->state) === SupportTicketState::Closed) {
                throw new DomainException('Closed support ticket cannot be assigned or transferred.');
            }

            if ($ticket->assigned_user_id === null || (int) $ticket->assigned_user_id !== $assigneeUserId) {
                $connection->table('support_tickets')->where('id', $ticketId)->update([
                    'assigned_user_id' => $assigneeUserId,
                    'updated_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                ]);
            }

            $row = $connection->table('support_tickets')->where('id', $ticketId)->first();
            if (! $row instanceof stdClass) {
                throw new RuntimeException('Support ticket disappeared during assignment.');
            }

            return $this->snapshotFromRow($row);
        });
    }

    private function snapshotFromRow(stdClass $row): SupportTicketSnapshot
    {
        return new SupportTicketSnapshot(
            (int) $row->id,
            (string) $row->tracking_number,
            (int) $row->requester_user_id,
            (int) $row->category_id,
            SupportTicketState::from((string) $row->state),
            SupportTicketPriority::from((string) $row->priority),
            $row->assigned_user_id === null ? null : (int) $row->assigned_user_id,
            (string) $row->title,
            $row->closed_at === null ? null : (string) $row->closed_at,
            $row->reopen_until === null ? null : (string) $row->reopen_until,
            (string) $row->created_at,
            (string) $row->updated_at,
        );
    }
}
