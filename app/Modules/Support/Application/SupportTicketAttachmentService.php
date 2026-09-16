<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Shared\Application\Clock;
use App\Shared\Application\RestrictedValue;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/** @requirement SUP-001 SUP-002 DAT-003 DAT-004 SEC-002 SEC-003 QUA-001 QUA-004 */
final readonly class SupportTicketAttachmentService
{
    private const MAX_BYTES = 20_000_000;

    /** @var array<string,string> */
    private const MIME_KINDS = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/webp' => 'image',
        'video/mp4' => 'video',
        'application/pdf' => 'file',
        'text/plain' => 'file',
    ];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private SupportTicketService $tickets,
        private AdministratorUserPermissionAuthorizer $authorizer,
    ) {}

    public function addForCustomer(
        int $ticketId,
        int $requesterUserId,
        string $kind,
        string $detectedMime,
        int $byteSize,
        string $contentSha256,
        RestrictedValue $privateMediaReference,
        string $idempotencyKey,
    ): SupportTicketAttachmentReceipt {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);
        $privateReference = $privateMediaReference->reveal();
        $this->assertAttachmentInput($kind, $detectedMime, $byteSize, $contentSha256, $privateReference, $idempotencyKey);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $ticketId,
            $requesterUserId,
            $kind,
            $detectedMime,
            $byteSize,
            $contentSha256,
            $privateReference,
            $idempotencyKey,
        ): SupportTicketAttachmentReceipt {
            $this->assertCustomerTicket($connection, $ticketId, $requesterUserId);
            $receipt = $this->persist(
                $connection,
                $ticketId,
                $requesterUserId,
                $kind,
                $detectedMime,
                $byteSize,
                $contentSha256,
                $privateReference,
                $idempotencyKey,
            );
            $this->tickets->replyAsCustomer(
                $ticketId,
                $requesterUserId,
                $this->historyBody($receipt->attachment),
                $this->historyIdempotencyKey($idempotencyKey),
            );

            return $receipt;
        }, 3);
    }

    public function addForSupport(
        int $actorUserId,
        int $ticketId,
        string $kind,
        string $detectedMime,
        int $byteSize,
        string $contentSha256,
        RestrictedValue $privateMediaReference,
        string $idempotencyKey,
    ): SupportTicketAttachmentReceipt {
        $this->assertPositiveId($actorUserId);
        $this->assertPositiveId($ticketId);
        $privateReference = $privateMediaReference->reveal();
        $this->assertAttachmentInput($kind, $detectedMime, $byteSize, $contentSha256, $privateReference, $idempotencyKey);
        $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $ticketId,
            $kind,
            $detectedMime,
            $byteSize,
            $contentSha256,
            $privateReference,
            $idempotencyKey,
        ): SupportTicketAttachmentReceipt {
            $this->assertTicketExists($connection, $ticketId);
            $receipt = $this->persist(
                $connection,
                $ticketId,
                $actorUserId,
                $kind,
                $detectedMime,
                $byteSize,
                $contentSha256,
                $privateReference,
                $idempotencyKey,
            );
            $this->tickets->addSupportMessage(
                $ticketId,
                $actorUserId,
                $this->historyBody($receipt->attachment),
                $this->historyIdempotencyKey($idempotencyKey),
                false,
            );

            return $receipt;
        }, 3);
    }

    /** @return list<SupportTicketAttachmentSnapshot> */
    public function attachmentsForCustomer(int $ticketId, int $requesterUserId, int $limit = 100): array
    {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);
        $this->assertLimit($limit);
        $connection = $this->database->connection();
        $this->assertCustomerTicket($connection, $ticketId, $requesterUserId);

        return $this->snapshotsForTicket($connection, $ticketId, $limit);
    }

    /** @return list<SupportTicketAttachmentSnapshot> */
    public function attachmentsForSupport(int $actorUserId, int $ticketId, int $limit = 100): array
    {
        $this->assertPositiveId($actorUserId);
        $this->assertPositiveId($ticketId);
        $this->assertLimit($limit);
        $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);
        $connection = $this->database->connection();
        $this->assertTicketExists($connection, $ticketId);

        return $this->snapshotsForTicket($connection, $ticketId, $limit);
    }

    public function deliveryForCustomer(string $attachmentPublicId, int $requesterUserId): SupportTicketAttachmentDeliveryGrant
    {
        $this->assertAttachmentPublicId($attachmentPublicId);
        $this->assertPositiveId($requesterUserId);

        /** @var stdClass|null $row */
        $row = $this->database->connection()->table('support_ticket_attachments as attachment')
            ->join('support_tickets as ticket', 'ticket.id', '=', 'attachment.ticket_id')
            ->where('attachment.public_id', strtoupper($attachmentPublicId))
            ->where('ticket.requester_user_id', $requesterUserId)
            ->where('attachment.customer_visible', 1)
            ->first($this->grantColumns());
        if ($row === null) {
            throw new RuntimeException('Support ticket attachment is unavailable for this customer.');
        }

        return $this->deliveryGrant($row);
    }

    public function deliveryForSupport(int $actorUserId, string $attachmentPublicId): SupportTicketAttachmentDeliveryGrant
    {
        $this->assertPositiveId($actorUserId);
        $this->assertAttachmentPublicId($attachmentPublicId);
        $this->authorizer->authorizeUser($actorUserId, SupportTicketSupportService::PERMISSION);

        /** @var stdClass|null $row */
        $row = $this->database->connection()->table('support_ticket_attachments as attachment')
            ->where('attachment.public_id', strtoupper($attachmentPublicId))
            ->first($this->grantColumns());
        if ($row === null) {
            throw new RuntimeException('Support ticket attachment does not exist.');
        }

        return $this->deliveryGrant($row);
    }

    private function persist(
        Connection $connection,
        int $ticketId,
        int $actorUserId,
        string $kind,
        string $detectedMime,
        int $byteSize,
        string $contentSha256,
        string $privateReference,
        string $idempotencyKey,
    ): SupportTicketAttachmentReceipt {
        /** @var stdClass|null $existing */
        $existing = $connection->table('support_ticket_attachments')
            ->where('ticket_id', $ticketId)
            ->where('idempotency_key', $idempotencyKey)
            ->first($this->storedColumns());
        if ($existing !== null) {
            $this->assertStoredMatches(
                $existing,
                $actorUserId,
                $kind,
                $detectedMime,
                $byteSize,
                $contentSha256,
                $privateReference,
            );

            return new SupportTicketAttachmentReceipt($this->snapshot($existing), true);
        }

        $publicId = strtoupper((string) Str::ulid());
        $inserted = $connection->table('support_ticket_attachments')->insertOrIgnore([
            'public_id' => $publicId,
            'ticket_id' => $ticketId,
            'actor_user_id' => $actorUserId,
            'kind' => $kind,
            'detected_mime' => $detectedMime,
            'byte_size' => $byteSize,
            'content_sha256' => strtolower($contentSha256),
            'private_media_reference' => $privateReference,
            'idempotency_key' => $idempotencyKey,
            'customer_visible' => 1,
            'created_at' => $this->timestamp(),
        ]);

        /** @var stdClass|null $stored */
        $stored = $connection->table('support_ticket_attachments')
            ->where('ticket_id', $ticketId)
            ->where('idempotency_key', $idempotencyKey)
            ->first($this->storedColumns());
        if ($stored === null) {
            throw new DomainException('Support ticket attachment conflicts with existing durable private media.');
        }
        $this->assertStoredMatches(
            $stored,
            $actorUserId,
            $kind,
            $detectedMime,
            $byteSize,
            $contentSha256,
            $privateReference,
        );

        return new SupportTicketAttachmentReceipt($this->snapshot($stored), $inserted === 0);
    }

    /** @return list<SupportTicketAttachmentSnapshot> */
    private function snapshotsForTicket(Connection $connection, int $ticketId, int $limit): array
    {
        $snapshots = [];
        foreach ($connection->table('support_ticket_attachments')
            ->where('ticket_id', $ticketId)
            ->where('customer_visible', 1)
            ->orderBy('id')
            ->limit($limit)
            ->get($this->snapshotColumns()) as $row) {
            $snapshots[] = $this->snapshot($row);
        }

        return $snapshots;
    }

    private function deliveryGrant(stdClass $row): SupportTicketAttachmentDeliveryGrant
    {
        return new SupportTicketAttachmentDeliveryGrant(
            $this->snapshot($row),
            RestrictedValue::fromString((string) $row->private_media_reference),
        );
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

    private function assertStoredMatches(
        stdClass $row,
        int $actorUserId,
        string $kind,
        string $detectedMime,
        int $byteSize,
        string $contentSha256,
        string $privateReference,
    ): void {
        if ((int) $row->actor_user_id !== $actorUserId
            || (string) $row->kind !== $kind
            || (string) $row->detected_mime !== $detectedMime
            || (int) $row->byte_size !== $byteSize
            || ! hash_equals((string) $row->content_sha256, strtolower($contentSha256))
            || ! hash_equals((string) $row->private_media_reference, $privateReference)
            || (int) $row->customer_visible !== 1) {
            throw new DomainException('Support ticket attachment idempotency key was reused with conflicting payload.');
        }
    }

    private function assertAttachmentInput(
        string $kind,
        string $detectedMime,
        int $byteSize,
        string $contentSha256,
        string $privateReference,
        string $idempotencyKey,
    ): void {
        $expectedKind = self::MIME_KINDS[$detectedMime] ?? null;
        if ($expectedKind === null || $kind !== $expectedKind) {
            throw new InvalidArgumentException('Support ticket attachment media type is not approved.');
        }
        if ($byteSize < 1 || $byteSize > self::MAX_BYTES) {
            throw new InvalidArgumentException('Support ticket attachment size is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', strtolower($contentSha256)) !== 1) {
            throw new InvalidArgumentException('Support ticket attachment content hash is invalid.');
        }
        $this->assertOpaqueToken($privateReference, 'Support ticket attachment private-media reference', 191);
        $this->assertOpaqueToken($idempotencyKey, 'Support ticket attachment idempotency key', 128);
    }

    private function assertOpaqueToken(string $value, string $label, int $maximum): void
    {
        if ($value === ''
            || strlen($value) > $maximum
            || trim($value) !== $value
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function assertCustomerTicket(Connection $connection, int $ticketId, int $requesterUserId): void
    {
        if (! $connection->table('support_tickets')
            ->where('id', $ticketId)
            ->where('requester_user_id', $requesterUserId)
            ->exists()) {
            throw new DomainException('Support ticket is unavailable for this customer.');
        }
    }

    private function assertTicketExists(Connection $connection, int $ticketId): void
    {
        if (! $connection->table('support_tickets')->where('id', $ticketId)->exists()) {
            throw new DomainException('Support ticket does not exist.');
        }
    }

    private function assertAttachmentPublicId(string $publicId): void
    {
        if (! Str::isUlid($publicId)) {
            throw new InvalidArgumentException('Support ticket attachment identity is invalid.');
        }
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Support ticket attachment identity must be positive.');
        }
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Support ticket attachment list limit is invalid.');
        }
    }

    private function historyBody(SupportTicketAttachmentSnapshot $attachment): string
    {
        return sprintf(
            '📎 %s · %s · %s · %d B',
            $attachment->publicId,
            $attachment->kind,
            $attachment->detectedMime,
            $attachment->byteSize,
        );
    }

    private function historyIdempotencyKey(string $idempotencyKey): string
    {
        return 'attachment-history:'.hash('sha256', $idempotencyKey);
    }

    /** @return list<string> */
    private function snapshotColumns(): array
    {
        return ['public_id', 'ticket_id', 'actor_user_id', 'kind', 'detected_mime', 'byte_size', 'customer_visible', 'created_at'];
    }

    /** @return list<string> */
    private function storedColumns(): array
    {
        return [...$this->snapshotColumns(), 'content_sha256', 'private_media_reference'];
    }

    /** @return list<string> */
    private function grantColumns(): array
    {
        return [
            'attachment.public_id',
            'attachment.ticket_id',
            'attachment.actor_user_id',
            'attachment.kind',
            'attachment.detected_mime',
            'attachment.byte_size',
            'attachment.customer_visible',
            'attachment.created_at',
            'attachment.private_media_reference',
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
