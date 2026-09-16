<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain;

enum SupportTicketMessageKind: string
{
    case CustomerReply = 'customer_reply';
    case SupportReply = 'support_reply';
    case InternalNote = 'internal_note';
}
