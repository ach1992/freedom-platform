<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain;

enum SupportTicketState: string
{
    case New = 'new';
    case AwaitingSupport = 'awaiting_support';
    case AwaitingCustomer = 'awaiting_customer';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
