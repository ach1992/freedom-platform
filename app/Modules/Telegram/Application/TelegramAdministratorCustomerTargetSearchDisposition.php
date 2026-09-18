<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramAdministratorCustomerTargetSearchDisposition: string
{
    case Matched = 'matched';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
}
