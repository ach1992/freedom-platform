<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramInteractionDispatchStatus: string
{
    case Ignored = 'ignored';
    case Handled = 'handled';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Replayed = 'replayed';
}
