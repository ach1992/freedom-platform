<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

enum PurchaseOrderSettlementAvailability: string
{
    case Absent = 'absent';
    case AwaitingPayment = 'awaiting_payment';
    case Unavailable = 'unavailable';
}
