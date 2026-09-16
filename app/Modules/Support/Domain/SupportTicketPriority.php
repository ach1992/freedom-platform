<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain;

enum SupportTicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
