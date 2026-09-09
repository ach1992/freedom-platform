<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramInlineHttpsUrlPurpose: string
{
    case ZarinpalStartPay = 'zarinpal_start_pay';
}
