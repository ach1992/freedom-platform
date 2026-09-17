<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramSupportCustomerRateLimitScope: string
{
    case TicketCreation = 'ticket_creation';
    case CustomerContent = 'customer_content';
}
