<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketRatingSnapshot
{
    public function __construct(
        public int $id,
        public int $ticketId,
        public int $requesterUserId,
        public int $score,
        public string $createdAt,
    ) {}
}
