<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\SupportTicketMessageKind;

final readonly class SupportTicketMessageSnapshot
{
    public function __construct(
        public int $id,
        public int $ticketId,
        public int $actorUserId,
        public SupportTicketMessageKind $kind,
        public string $body,
        public bool $customerVisible,
        public string $createdAt,
    ) {}
}
