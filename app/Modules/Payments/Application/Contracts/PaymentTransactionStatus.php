<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Settled = 'settled';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
    case Reversed = 'reversed';
    case Unknown = 'unknown';
}
