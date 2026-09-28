<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketCustomerNotificationSnapshot
{
    public function __construct(
        public int $messageId,
        public int $ticketId,
        public string $trackingNumber,
        public int $requesterUserId,
        public string $body,
    ) {}
}
