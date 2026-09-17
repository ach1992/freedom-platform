<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketRatingReceipt
{
    public function __construct(
        public SupportTicketRatingSnapshot $rating,
        public bool $replayed,
    ) {}
}
