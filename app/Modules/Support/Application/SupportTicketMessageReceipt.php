<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\SupportTicketMessageKind;

final readonly class SupportTicketMessageReceipt
{
    public function __construct(
        public int $messageId,
        public int $ticketId,
        public SupportTicketMessageKind $kind,
        public bool $replayed,
    ) {}
}
