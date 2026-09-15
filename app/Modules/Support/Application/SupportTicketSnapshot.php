<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\SupportTicketPriority;
use App\Modules\Support\Domain\SupportTicketState;

final readonly class SupportTicketSnapshot
{
    public function __construct(
        public int $id,
        public string $trackingNumber,
        public int $requesterUserId,
        public int $categoryId,
        public SupportTicketState $state,
        public SupportTicketPriority $priority,
        public ?int $assignedUserId,
        public string $title,
        public ?string $closedAt,
        public ?string $reopenUntil,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
