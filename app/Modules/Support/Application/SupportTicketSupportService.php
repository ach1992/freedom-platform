<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\AccessControl\Application\AdministratorRoleContextReader;
use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Support\Domain\SupportTicketPriority;
use App\Modules\Support\Domain\SupportTicketState;

final readonly class SupportTicketSupportService
{
    public const PERMISSION = 'support.tickets.manage';

    public function __construct(
        private SupportTicketService $tickets,
        private AdministratorUserPermissionAuthorizer $authorizer,
        private AdministratorRoleContextReader $roles,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->authorizer->allowsUser($actorUserId, self::PERMISSION);
    }

    /** @return list<SupportTicketSnapshot> */
    public function queue(int $actorUserId, int $limit = 50): array
    {
        $administratorId = $this->authorize($actorUserId);
        $roleContext = $this->roles->forAdministrator($administratorId);

        return $this->tickets->ticketsForSupport(
            $limit,
            new SupportTicketQueueRouteFilter($actorUserId, $roleContext->isOwner, $roleContext->roleCodes),
        );
    }

    public function detail(int $actorUserId, int $ticketId): SupportTicketDetailSnapshot
    {
        $this->authorize($actorUserId);

        return $this->tickets->ticketForSupport($ticketId);
    }

    /** @return list<SupportTicketState> */
    public function allowedTransitions(int $actorUserId, int $ticketId): array
    {
        $this->authorize($actorUserId);

        return $this->tickets->allowedTransitions($ticketId);
    }

    public function claim(int $actorUserId, int $ticketId): SupportTicketSnapshot
    {
        $this->authorize($actorUserId);

        return $this->tickets->claim($ticketId, $actorUserId);
    }

    public function setPriority(
        int $actorUserId,
        int $ticketId,
        SupportTicketPriority $priority,
    ): SupportTicketSnapshot {
        $this->authorize($actorUserId);

        return $this->tickets->setPriority($ticketId, $priority);
    }

    public function reply(
        int $actorUserId,
        int $ticketId,
        string $body,
        string $idempotencyKey,
    ): SupportTicketMessageReceipt {
        $this->authorize($actorUserId);

        return $this->tickets->addSupportMessage($ticketId, $actorUserId, $body, $idempotencyKey, false);
    }

    public function internalNote(
        int $actorUserId,
        int $ticketId,
        string $body,
        string $idempotencyKey,
    ): SupportTicketMessageReceipt {
        $this->authorize($actorUserId);

        return $this->tickets->addSupportMessage($ticketId, $actorUserId, $body, $idempotencyKey, true);
    }

    public function transition(
        int $actorUserId,
        int $ticketId,
        SupportTicketState $target,
        string $reasonCode,
        ?string $closeReason = null,
    ): SupportTicketSnapshot {
        $this->authorize($actorUserId);

        return $this->tickets->transition($ticketId, $target, $actorUserId, $reasonCode, $closeReason);
    }

    private function authorize(int $actorUserId): int
    {
        return $this->authorizer->authorizeUser($actorUserId, self::PERMISSION);
    }
}
