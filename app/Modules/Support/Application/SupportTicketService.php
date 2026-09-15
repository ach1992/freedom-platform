<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\SupportTicketMessageKind;
use App\Modules\Support\Domain\SupportTicketPriority;
use App\Modules\Support\Domain\SupportTicketState;
use App\Modules\Support\Domain\SupportTicketTransitionPolicy;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class SupportTicketService
{
    private const DEFAULT_REOPEN_HOURS = 72;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private SupportTicketTransitionPolicy $transitionPolicy = new SupportTicketTransitionPolicy,
    ) {}

    /** @requirement SUP-001 SEC-002 DAT-003 QUA-004 */
    public function create(SupportTicketCreateRequest $request): SupportTicketSnapshot
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($request): SupportTicketSnapshot {
            /** @var object{id:int|string}|null $category */
            $category = $connection->table('support_ticket_categories')
                ->where('code', $request->categoryCode)
                ->where('is_active', 1)
                ->first(['id']);
            if ($category === null) {
                throw new DomainException('Support ticket category is unavailable.');
            }

            $now = $this->timestamp();
            $ticketId = (int) $connection->table('support_tickets')->insertGetId([
                'tracking_number' => $this->uniqueTrackingNumber($connection),
                'requester_user_id' => $request->requesterUserId,
                'category_id' => (int) $category->id,
                'state' => SupportTicketState::New->value,
                'priority' => $request->priority->value,
                'assigned_user_id' => null,
                'order_id' => $request->orderId,
                'payment_intent_id' => $request->paymentIntentId,
                'service_subscription_id' => $request->serviceSubscriptionId,
                'title' => trim($request->title),
                'close_reason' => null,
                'resolved_at' => null,
                'closed_at' => null,
                'reopen_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->insertMessage(
                $connection,
                $ticketId,
                $request->requesterUserId,
                SupportTicketMessageKind::CustomerReply,
                $request->description,
                $request->idempotencyKey,
                true,
                $now,
            );
            $this->recordStateChange($connection, $ticketId, null, SupportTicketState::New, $request->requesterUserId, 'ticket_created', $now);

            return $this->snapshot($connection, $ticketId);
        });
    }

    /** @return list<SupportTicketSnapshot> */
    public function ticketsForCustomer(int $requesterUserId, int $limit = 50): array
    {
        if ($requesterUserId < 1 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Support ticket customer query is invalid.');
        }

        $rows = $this->database->connection()->table('support_tickets')
            ->where('requester_user_id', $requesterUserId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = $this->snapshotFromRow($row);
        }

        return $tickets;
    }

    /** @requirement SUP-001 */
    public function triage(
        int $ticketId,
        ?int $assignedUserId,
        SupportTicketPriority $priority,
    ): SupportTicketSnapshot {
        $this->assertPositiveId($ticketId);
        if ($assignedUserId !== null) {
            $this->assertPositiveId($assignedUserId);
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $assignedUserId, $priority): SupportTicketSnapshot {
            /** @var object{id:int|string}|null $ticket */
            $ticket = $connection->table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first(['id']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket does not exist.');
            }

            if ($assignedUserId !== null && ! $connection->table('users')->where('id', $assignedUserId)->exists()) {
                throw new InvalidArgumentException('Support ticket assignee does not exist.');
            }

            $connection->table('support_tickets')->where('id', $ticketId)->update([
                'assigned_user_id' => $assignedUserId,
                'priority' => $priority->value,
                'updated_at' => $this->timestamp(),
            ]);

            return $this->snapshot($connection, $ticketId);
        });
    }

    /** @requirement SUP-001 SEC-002 QUA-003 */
    public function replyAsCustomer(
        int $ticketId,
        int $requesterUserId,
        string $body,
        string $idempotencyKey,
    ): SupportTicketMessageReceipt {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);
        $this->assertMessage($body, $idempotencyKey);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $requesterUserId, $body, $idempotencyKey): SupportTicketMessageReceipt {
            /** @var object{id:int|string,state:string,reopen_until:?string}|null $ticket */
            $ticket = $connection->table('support_tickets')
                ->where('id', $ticketId)
                ->where('requester_user_id', $requesterUserId)
                ->lockForUpdate()
                ->first(['id', 'state', 'reopen_until']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket is unavailable for this customer.');
            }

            $state = SupportTicketState::from($ticket->state);
            if (in_array($state, [SupportTicketState::Resolved, SupportTicketState::Closed], true)) {
                throw new DomainException('Resolved or closed support ticket must be reopened before replying.');
            }

            $now = $this->timestamp();
            $receipt = $this->insertMessage(
                $connection,
                $ticketId,
                $requesterUserId,
                SupportTicketMessageKind::CustomerReply,
                $body,
                $idempotencyKey,
                true,
                $now,
            );
            if ($receipt->replayed) {
                return $receipt;
            }

            if ($state === SupportTicketState::AwaitingCustomer) {
                $this->applyTransition($connection, $ticketId, $state, SupportTicketState::AwaitingSupport, $requesterUserId, 'customer_reply', null, null);
            }

            return $receipt;
        });
    }

    /** @requirement SUP-001 DAT-003 */
    public function addSupportMessage(
        int $ticketId,
        int $actorUserId,
        string $body,
        string $idempotencyKey,
        bool $internalNote = false,
    ): SupportTicketMessageReceipt {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($actorUserId);
        $this->assertMessage($body, $idempotencyKey);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $actorUserId, $body, $idempotencyKey, $internalNote): SupportTicketMessageReceipt {
            /** @var object{id:int|string,state:string}|null $ticket */
            $ticket = $connection->table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first(['id', 'state']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket does not exist.');
            }

            $state = SupportTicketState::from($ticket->state);
            if (! $internalNote && in_array($state, [SupportTicketState::Resolved, SupportTicketState::Closed], true)) {
                throw new DomainException('Resolved or closed support ticket must be reopened before a public support reply.');
            }

            $now = $this->timestamp();
            $kind = $internalNote ? SupportTicketMessageKind::InternalNote : SupportTicketMessageKind::SupportReply;
            $receipt = $this->insertMessage($connection, $ticketId, $actorUserId, $kind, $body, $idempotencyKey, ! $internalNote, $now);
            if ($receipt->replayed || $internalNote || $state === SupportTicketState::AwaitingCustomer) {
                return $receipt;
            }

            $this->applyTransition($connection, $ticketId, $state, SupportTicketState::AwaitingCustomer, $actorUserId, 'support_reply', null, null);

            return $receipt;
        });
    }

    /** @requirement SUP-001 */
    public function transition(
        int $ticketId,
        SupportTicketState $target,
        int $actorUserId,
        string $reasonCode,
        ?string $closeReason = null,
        int $reopenHours = self::DEFAULT_REOPEN_HOURS,
    ): SupportTicketSnapshot {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($actorUserId);
        $this->assertReason($reasonCode);
        if ($reopenHours < 1 || $reopenHours > 24 * 30) {
            throw new InvalidArgumentException('Support ticket reopen window is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $target, $actorUserId, $reasonCode, $closeReason, $reopenHours): SupportTicketSnapshot {
            /** @var object{id:int|string,state:string,reopen_until:?string}|null $ticket */
            $ticket = $connection->table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first(['id', 'state', 'reopen_until']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket does not exist.');
            }

            $this->applyTransition(
                $connection,
                $ticketId,
                SupportTicketState::from($ticket->state),
                $target,
                $actorUserId,
                $reasonCode,
                $closeReason,
                $reopenHours,
            );

            return $this->snapshot($connection, $ticketId);
        });
    }

    /** @requirement SUP-001 SEC-002 */
    public function closeForCustomer(int $ticketId, int $requesterUserId, string $closeReason): SupportTicketSnapshot
    {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $requesterUserId, $closeReason): SupportTicketSnapshot {
            /** @var object{id:int|string,state:string}|null $ticket */
            $ticket = $connection->table('support_tickets')
                ->where('id', $ticketId)
                ->where('requester_user_id', $requesterUserId)
                ->lockForUpdate()
                ->first(['id', 'state']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket is unavailable for this customer.');
            }

            $this->applyTransition(
                $connection,
                $ticketId,
                SupportTicketState::from($ticket->state),
                SupportTicketState::Closed,
                $requesterUserId,
                'customer_closed',
                $closeReason,
                self::DEFAULT_REOPEN_HOURS,
            );

            return $this->snapshot($connection, $ticketId);
        });
    }

    /** @requirement SUP-001 SEC-002 */
    public function reopenForCustomer(int $ticketId, int $requesterUserId): SupportTicketSnapshot
    {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $requesterUserId): SupportTicketSnapshot {
            /** @var object{id:int|string,state:string,reopen_until:?string}|null $ticket */
            $ticket = $connection->table('support_tickets')
                ->where('id', $ticketId)
                ->where('requester_user_id', $requesterUserId)
                ->lockForUpdate()
                ->first(['id', 'state', 'reopen_until']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket is unavailable for this customer.');
            }

            $state = SupportTicketState::from($ticket->state);
            if ($state !== SupportTicketState::Closed) {
                throw new DomainException('Only a closed support ticket can be reopened by the customer.');
            }

            $this->applyTransition(
                $connection,
                $ticketId,
                $state,
                SupportTicketState::AwaitingSupport,
                $requesterUserId,
                'customer_reopen',
                null,
                null,
            );

            return $this->snapshot($connection, $ticketId);
        });
    }

    private function applyTransition(
        Connection $connection,
        int $ticketId,
        SupportTicketState $from,
        SupportTicketState $to,
        int $actorUserId,
        string $reasonCode,
        ?string $closeReason,
        ?int $reopenHours,
    ): void {
        /** @var object{reopen_until:?string,resolved_at:?string,closed_at:?string}|null $current */
        $current = $connection->table('support_tickets')->where('id', $ticketId)->first(['reopen_until', 'resolved_at', 'closed_at']);
        if ($current === null) {
            throw new RuntimeException('Support ticket disappeared during transition.');
        }

        $nowObject = $this->clock->now();
        $this->transitionPolicy->assertAllowed($from, $to, $nowObject, $this->parseTimestamp($current->reopen_until));
        $now = $this->formatTimestamp($nowObject);

        $updates = [
            'state' => $to->value,
            'updated_at' => $now,
        ];

        if ($to === SupportTicketState::Resolved) {
            $updates['resolved_at'] = $now;
        }
        if ($to === SupportTicketState::Closed) {
            if ($closeReason === null || trim($closeReason) === '' || mb_strlen($closeReason) > 500) {
                throw new InvalidArgumentException('Closing a support ticket requires a valid close reason.');
            }
            $hours = $reopenHours ?? self::DEFAULT_REOPEN_HOURS;
            $updates['close_reason'] = trim($closeReason);
            $updates['closed_at'] = $now;
            $updates['reopen_until'] = $this->formatTimestamp($nowObject->modify('+'.$hours.' hours'));
        }
        if ($from === SupportTicketState::Closed && $to === SupportTicketState::AwaitingSupport) {
            $updates['close_reason'] = null;
            $updates['resolved_at'] = null;
            $updates['closed_at'] = null;
            $updates['reopen_until'] = null;
        }
        if ($from === SupportTicketState::Resolved && $to === SupportTicketState::AwaitingSupport) {
            $updates['resolved_at'] = null;
        }

        $connection->table('support_tickets')->where('id', $ticketId)->update($updates);
        $this->recordStateChange($connection, $ticketId, $from, $to, $actorUserId, $reasonCode, $now);
    }

    private function insertMessage(
        Connection $connection,
        int $ticketId,
        int $actorUserId,
        SupportTicketMessageKind $kind,
        string $body,
        string $idempotencyKey,
        bool $customerVisible,
        string $createdAt,
    ): SupportTicketMessageReceipt {
        $normalizedBody = trim($body);
        $inserted = $connection->table('support_ticket_messages')->insertOrIgnore([
            'ticket_id' => $ticketId,
            'actor_user_id' => $actorUserId,
            'kind' => $kind->value,
            'body' => $normalizedBody,
            'idempotency_key' => $idempotencyKey,
            'customer_visible' => $customerVisible ? 1 : 0,
            'created_at' => $createdAt,
        ]);

        /** @var object{id:int|string,kind:string,actor_user_id:int|string,body:string,customer_visible:int|string|bool}|null $message */
        $message = $connection->table('support_ticket_messages')
            ->where('ticket_id', $ticketId)
            ->where('idempotency_key', $idempotencyKey)
            ->first(['id', 'kind', 'actor_user_id', 'body', 'customer_visible']);
        if ($message === null) {
            throw new RuntimeException('Support ticket message could not be persisted.');
        }
        if ($message->kind !== $kind->value
            || (int) $message->actor_user_id !== $actorUserId
            || $message->body !== $normalizedBody
            || (int) $message->customer_visible !== ($customerVisible ? 1 : 0)) {
            throw new DomainException('Support ticket idempotency key was reused for a different message payload.');
        }

        return new SupportTicketMessageReceipt((int) $message->id, $ticketId, $kind, $inserted === 0);
    }

    private function recordStateChange(
        Connection $connection,
        int $ticketId,
        ?SupportTicketState $from,
        SupportTicketState $to,
        int $actorUserId,
        string $reasonCode,
        string $createdAt,
    ): void {
        $connection->table('support_ticket_state_histories')->insert([
            'ticket_id' => $ticketId,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'actor_user_id' => $actorUserId,
            'reason_code' => $reasonCode,
            'created_at' => $createdAt,
        ]);
    }

    private function uniqueTrackingNumber(Connection $connection): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = 'TKT-'.Str::upper(Str::random(16));
            if (! $connection->table('support_tickets')->where('tracking_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique support ticket tracking number.');
    }

    private function snapshot(Connection $connection, int $ticketId): SupportTicketSnapshot
    {
        $row = $connection->table('support_tickets')->where('id', $ticketId)->first();
        if ($row === null) {
            throw new RuntimeException('Support ticket does not exist.');
        }

        return $this->snapshotFromRow($row);
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

    private function assertMessage(string $body, string $idempotencyKey): void
    {
        if (trim($body) === '' || mb_strlen($body) > 8000) {
            throw new InvalidArgumentException('Support ticket message is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Support ticket message idempotency key is invalid.');
        }
    }

    private function assertReason(string $reasonCode): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Support ticket transition reason is invalid.');
        }
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Support ticket identity is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->formatTimestamp($this->clock->now());
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseTimestamp(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException('Stored support ticket timestamp is invalid.');
        }

        return $parsed;
    }
}
