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
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class SupportTicketService
{
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
                'state_version' => 1,
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
                'last_transition_actor_user_id' => $request->requesterUserId,
                'last_transition_reason_code' => 'ticket_created',
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

    /** @return list<SupportTicketCategorySnapshot> */
    public function activeCategories(): array
    {
        $rows = $this->database->connection()->table('support_ticket_categories')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name_fa', 'name_en', 'sort_order']);

        $categories = [];
        foreach ($rows as $row) {
            $categories[] = new SupportTicketCategorySnapshot(
                (int) $row->id,
                (string) $row->code,
                (string) $row->name_fa,
                (string) $row->name_en,
                (int) $row->sort_order,
            );
        }

        return $categories;
    }

    /** @requirement SUP-001 SEC-002 */
    public function ticketForCustomer(int $ticketId, int $requesterUserId): SupportTicketDetailSnapshot
    {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($requesterUserId);
        $connection = $this->database->connection();
        $ticket = $connection->table('support_tickets')
            ->where('id', $ticketId)
            ->where('requester_user_id', $requesterUserId)
            ->first();
        if (! $ticket instanceof stdClass) {
            throw new RuntimeException('Support ticket is unavailable for this customer.');
        }

        return $this->detailFromRow($connection, $ticket, true);
    }

    /** @return list<SupportTicketSnapshot> */
    public function ticketsForSupport(
        int $limit = 50,
        ?SupportTicketQueueRouteFilter $routeFilter = null,
    ): array {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Support ticket queue query is invalid.');
        }

        $query = $this->database->connection()->table('support_tickets')
            ->select('support_tickets.*')
            ->where('support_tickets.state', '<>', SupportTicketState::Closed->value);

        if ($routeFilter !== null && ! $routeFilter->allRoutes) {
            $query->join('support_ticket_categories as categories', 'categories.id', '=', 'support_tickets.category_id')
                ->where(function (Builder $routing) use ($routeFilter): void {
                    $routing->whereNull('categories.route_role_code')
                        ->orWhere('support_tickets.assigned_user_id', $routeFilter->actorUserId);
                    if ($routeFilter->roleCodes !== []) {
                        $routing->orWhereIn('categories.route_role_code', $routeFilter->roleCodes);
                    }
                });
        }

        $rows = $query
            ->orderByRaw("CASE support_tickets.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderBy('support_tickets.created_at')
            ->orderBy('support_tickets.id')
            ->limit($limit)
            ->get();

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = $this->snapshotFromRow($row);
        }

        return $tickets;
    }

    public function ticketForSupport(int $ticketId): SupportTicketDetailSnapshot
    {
        $this->assertPositiveId($ticketId);
        $connection = $this->database->connection();
        $ticket = $connection->table('support_tickets')->where('id', $ticketId)->first();
        if (! $ticket instanceof stdClass) {
            throw new RuntimeException('Support ticket does not exist.');
        }

        return $this->detailFromRow($connection, $ticket, false);
    }

    /** @return list<SupportTicketState> */
    public function allowedTransitions(int $ticketId): array
    {
        $this->assertPositiveId($ticketId);
        $ticket = $this->database->connection()->table('support_tickets')
            ->where('id', $ticketId)
            ->first(['state', 'reopen_until']);
        if (! $ticket instanceof stdClass) {
            throw new RuntimeException('Support ticket does not exist.');
        }

        return $this->transitionPolicy->allowedTargets(
            SupportTicketState::from((string) $ticket->state),
            $this->clock->now(),
            $this->parseTimestamp($ticket->reopen_until === null ? null : (string) $ticket->reopen_until),
        );
    }

    /** @requirement SUP-002 SEC-002 QUA-003 */
    public function claim(int $ticketId, int $assignedUserId): SupportTicketSnapshot
    {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($assignedUserId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $assignedUserId): SupportTicketSnapshot {
            /** @var object{id:int|string,assigned_user_id:int|string|null,state:string}|null $ticket */
            $ticket = $connection->table('support_tickets')
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first(['id', 'assigned_user_id', 'state']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket does not exist.');
            }
            if (SupportTicketState::from($ticket->state) === SupportTicketState::Closed) {
                throw new DomainException('Closed support ticket cannot be claimed.');
            }
            if (! $connection->table('users')->where('id', $assignedUserId)->exists()) {
                throw new InvalidArgumentException('Support ticket assignee does not exist.');
            }
            if ($ticket->assigned_user_id !== null && (int) $ticket->assigned_user_id !== $assignedUserId) {
                throw new DomainException('Support ticket is already assigned to another user.');
            }
            if ($ticket->assigned_user_id === null) {
                $connection->table('support_tickets')->where('id', $ticketId)->update([
                    'assigned_user_id' => $assignedUserId,
                    'updated_at' => $this->timestamp(),
                ]);
            }

            return $this->snapshot($connection, $ticketId);
        });
    }

    /** @requirement SUP-002 */
    public function setPriority(int $ticketId, SupportTicketPriority $priority): SupportTicketSnapshot
    {
        $this->assertPositiveId($ticketId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $priority): SupportTicketSnapshot {
            /** @var object{id:int|string,state:string}|null $ticket */
            $ticket = $connection->table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first(['id', 'state']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket does not exist.');
            }
            if (SupportTicketState::from($ticket->state) === SupportTicketState::Closed) {
                throw new DomainException('Closed support ticket priority cannot change.');
            }
            $connection->table('support_tickets')->where('id', $ticketId)->update([
                'priority' => $priority->value,
                'updated_at' => $this->timestamp(),
            ]);

            return $this->snapshot($connection, $ticketId);
        });
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

            $replay = $this->existingMessageReceipt(
                $connection,
                $ticketId,
                $requesterUserId,
                SupportTicketMessageKind::CustomerReply,
                $body,
                $idempotencyKey,
                true,
            );
            if ($replay !== null) {
                return $replay;
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

            $kind = $internalNote ? SupportTicketMessageKind::InternalNote : SupportTicketMessageKind::SupportReply;
            $replay = $this->existingMessageReceipt(
                $connection,
                $ticketId,
                $actorUserId,
                $kind,
                $body,
                $idempotencyKey,
                ! $internalNote,
            );
            if ($replay !== null) {
                return $replay;
            }

            $state = SupportTicketState::from($ticket->state);
            if (! $internalNote && in_array($state, [SupportTicketState::Resolved, SupportTicketState::Closed], true)) {
                throw new DomainException('Resolved or closed support ticket must be reopened before a public support reply.');
            }

            $now = $this->timestamp();
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
    ): SupportTicketSnapshot {
        $this->assertPositiveId($ticketId);
        $this->assertPositiveId($actorUserId);
        $this->assertReason($reasonCode);

        return $this->database->connection()->transaction(function (Connection $connection) use ($ticketId, $target, $actorUserId, $reasonCode, $closeReason): SupportTicketSnapshot {
            /** @var object{id:int|string,state:string,reopen_until:?string}|null $ticket */
            $ticket = $connection->table('support_tickets')->where('id', $ticketId)->lockForUpdate()->first(['id', 'state', 'reopen_until']);
            if ($ticket === null) {
                throw new RuntimeException('Support ticket does not exist.');
            }

            $reopenHours = $target === SupportTicketState::Closed
                ? $this->configuredReopenWindowHours()
                : null;
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

            $reopenHours = $this->configuredReopenWindowHours();
            $this->applyTransition(
                $connection,
                $ticketId,
                SupportTicketState::from($ticket->state),
                SupportTicketState::Closed,
                $requesterUserId,
                'customer_closed',
                $closeReason,
                $reopenHours,
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
        /** @var object{state_version:int|string,reopen_until:?string,resolved_at:?string,closed_at:?string}|null $current */
        $current = $connection->table('support_tickets')->where('id', $ticketId)->first(['state_version', 'reopen_until', 'resolved_at', 'closed_at']);
        if ($current === null) {
            throw new RuntimeException('Support ticket disappeared during transition.');
        }

        $nowObject = $this->clock->now();
        $this->transitionPolicy->assertAllowed($from, $to, $nowObject, $this->parseTimestamp($current->reopen_until));
        $now = $this->formatTimestamp($nowObject);

        $updates = [
            'state' => $to->value,
            'state_version' => (int) $current->state_version + 1,
            'last_transition_actor_user_id' => $actorUserId,
            'last_transition_reason_code' => $reasonCode,
            'updated_at' => $now,
        ];

        if ($to === SupportTicketState::Resolved) {
            $updates['resolved_at'] = $now;
        }
        if ($to === SupportTicketState::Closed) {
            if ($closeReason === null || trim($closeReason) === '' || mb_strlen($closeReason) > 500) {
                throw new InvalidArgumentException('Closing a support ticket requires a valid close reason.');
            }
            if ($reopenHours === null) {
                throw new RuntimeException('Support ticket reopen window was not resolved before close.');
            }
            $updates['close_reason'] = trim($closeReason);
            $updates['closed_at'] = $now;
            $updates['reopen_until'] = $this->formatTimestamp($nowObject->modify('+'.$reopenHours.' hours'));
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
    }

    private function existingMessageReceipt(
        Connection $connection,
        int $ticketId,
        int $actorUserId,
        SupportTicketMessageKind $kind,
        string $body,
        string $idempotencyKey,
        bool $customerVisible,
    ): ?SupportTicketMessageReceipt {
        /** @var object{id:int|string,kind:string,actor_user_id:int|string,body:string,customer_visible:int|string|bool}|null $message */
        $message = $connection->table('support_ticket_messages')
            ->where('ticket_id', $ticketId)
            ->where('idempotency_key', $idempotencyKey)
            ->first(['id', 'kind', 'actor_user_id', 'body', 'customer_visible']);
        if ($message === null) {
            return null;
        }

        $this->assertStoredMessageMatches($message, $actorUserId, $kind, trim($body), $customerVisible);

        return new SupportTicketMessageReceipt((int) $message->id, $ticketId, $kind, true);
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
        $this->assertStoredMessageMatches($message, $actorUserId, $kind, $normalizedBody, $customerVisible);

        return new SupportTicketMessageReceipt((int) $message->id, $ticketId, $kind, $inserted === 0);
    }

    /** @param object{kind:string,actor_user_id:int|string,body:string,customer_visible:int|string|bool} $message */
    private function assertStoredMessageMatches(
        object $message,
        int $actorUserId,
        SupportTicketMessageKind $kind,
        string $normalizedBody,
        bool $customerVisible,
    ): void {
        if ($message->kind !== $kind->value
            || (int) $message->actor_user_id !== $actorUserId
            || $message->body !== $normalizedBody
            || (int) $message->customer_visible !== ($customerVisible ? 1 : 0)) {
            throw new DomainException('Support ticket idempotency key was reused for a different message payload.');
        }
    }

    private function detailFromRow(Connection $connection, stdClass $ticket, bool $customerOnly): SupportTicketDetailSnapshot
    {
        $query = $connection->table('support_ticket_messages')
            ->where('ticket_id', (int) $ticket->id)
            ->orderBy('id');
        if ($customerOnly) {
            $query->where('customer_visible', 1);
        }

        $messages = [];
        foreach ($query->get(['id', 'ticket_id', 'actor_user_id', 'kind', 'body', 'customer_visible', 'created_at']) as $message) {
            $messages[] = new SupportTicketMessageSnapshot(
                (int) $message->id,
                (int) $message->ticket_id,
                (int) $message->actor_user_id,
                SupportTicketMessageKind::from((string) $message->kind),
                (string) $message->body,
                (bool) $message->customer_visible,
                (string) $message->created_at,
            );
        }

        return new SupportTicketDetailSnapshot($this->snapshotFromRow($ticket), $messages);
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

    private function configuredReopenWindowHours(): int
    {
        $configured = config('support.reopen_window_hours');
        if (is_int($configured)) {
            $hours = $configured;
        } elseif (is_string($configured) && preg_match('/\A[1-9][0-9]{0,2}\z/', $configured) === 1) {
            $hours = (int) $configured;
        } else {
            throw new RuntimeException('Support ticket reopen window configuration is invalid.');
        }
        if ($hours < 1 || $hours > 24 * 30) {
            throw new RuntimeException('Support ticket reopen window configuration is out of bounds.');
        }

        return $hours;
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
