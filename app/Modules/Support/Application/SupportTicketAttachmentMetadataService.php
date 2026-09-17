<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/** @requirement SUP-001 SUP-002 DAT-003 SEC-002 QUA-001 */
final readonly class SupportTicketAttachmentMetadataService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $authorizer,
    ) {}

    /** @return list<SupportTicketAttachmentSnapshot> */
    public function forCustomer(int $ticketId, int $requesterUserId, int $limit = 20): array
    {
        $this->assertIdentity($ticketId, $requesterUserId);
        $this->assertLimit($limit);
        $connection = $this->database->connection();
        $this->assertCustomerTicket($connection, $ticketId, $requesterUserId);

        return $this->latestForTicket($connection, $ticketId, $limit);
    }

    /** @return list<SupportTicketAttachmentSnapshot> */
    public function forSupport(int $actorUserId, int $ticketId, int $limit = 20): array
    {
        $this->assertIdentity($actorUserId, $ticketId);
        $this->assertLimit($limit);
        $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);
        $connection = $this->database->connection();
        $this->assertTicketExists($connection, $ticketId);

        return $this->latestForTicket($connection, $ticketId, $limit);
    }

    public function attachmentForCustomer(
        int $ticketId,
        int $requesterUserId,
        string $attachmentPublicId,
    ): SupportTicketAttachmentSnapshot {
        $this->assertIdentity($ticketId, $requesterUserId);
        $this->assertPublicId($attachmentPublicId);
        $connection = $this->database->connection();
        $this->assertCustomerTicket($connection, $ticketId, $requesterUserId);

        return $this->attachmentForTicket($connection, $ticketId, $attachmentPublicId);
    }

    public function attachmentForSupport(
        int $actorUserId,
        int $ticketId,
        string $attachmentPublicId,
    ): SupportTicketAttachmentSnapshot {
        $this->assertIdentity($actorUserId, $ticketId);
        $this->assertPublicId($attachmentPublicId);
        $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);
        $connection = $this->database->connection();
        $this->assertTicketExists($connection, $ticketId);

        return $this->attachmentForTicket($connection, $ticketId, $attachmentPublicId);
    }

    /** @return list<SupportTicketAttachmentSnapshot> */
    private function latestForTicket(Connection $connection, int $ticketId, int $limit): array
    {
        $rows = $connection->table('support_ticket_attachments')
            ->where('ticket_id', $ticketId)
            ->where('customer_visible', 1)
            ->orderByDesc('id')
            ->limit($limit)
            ->get($this->columns())
            ->reverse()
            ->values();

        $snapshots = [];
        foreach ($rows as $row) {
            $snapshots[] = $this->snapshot($row);
        }

        return $snapshots;
    }

    private function attachmentForTicket(
        Connection $connection,
        int $ticketId,
        string $attachmentPublicId,
    ): SupportTicketAttachmentSnapshot {
        /** @var stdClass|null $row */
        $row = $connection->table('support_ticket_attachments')
            ->where('ticket_id', $ticketId)
            ->where('public_id', strtoupper($attachmentPublicId))
            ->where('customer_visible', 1)
            ->first($this->columns());
        if ($row === null) {
            throw new RuntimeException('Support ticket attachment is unavailable in this ticket.');
        }

        return $this->snapshot($row);
    }

    private function snapshot(stdClass $row): SupportTicketAttachmentSnapshot
    {
        return new SupportTicketAttachmentSnapshot(
            strtoupper((string) $row->public_id),
            (int) $row->ticket_id,
            (int) $row->actor_user_id,
            (string) $row->kind,
            (string) $row->detected_mime,
            (int) $row->byte_size,
            (bool) $row->customer_visible,
            (string) $row->created_at,
        );
    }

    private function assertCustomerTicket(Connection $connection, int $ticketId, int $requesterUserId): void
    {
        if (! $connection->table('support_tickets')
            ->where('id', $ticketId)
            ->where('requester_user_id', $requesterUserId)
            ->exists()) {
            throw new RuntimeException('Support ticket is unavailable for this customer.');
        }
    }

    private function assertTicketExists(Connection $connection, int $ticketId): void
    {
        if (! $connection->table('support_tickets')->where('id', $ticketId)->exists()) {
            throw new RuntimeException('Support ticket does not exist.');
        }
    }

    private function assertIdentity(int $first, int $second): void
    {
        if ($first < 1 || $second < 1) {
            throw new InvalidArgumentException('Support ticket attachment metadata identity is invalid.');
        }
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Support ticket attachment metadata limit is invalid.');
        }
    }

    private function assertPublicId(string $publicId): void
    {
        if (! Str::isUlid($publicId)) {
            throw new InvalidArgumentException('Support ticket attachment metadata identity is invalid.');
        }
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'public_id',
            'ticket_id',
            'actor_user_id',
            'kind',
            'detected_mime',
            'byte_size',
            'customer_visible',
            'created_at',
        ];
    }
}
