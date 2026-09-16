<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketDetailSnapshot
{
    /** @param list<SupportTicketMessageSnapshot> $messages */
    public function __construct(
        public SupportTicketSnapshot $ticket,
        public array $messages,
    ) {}
}
