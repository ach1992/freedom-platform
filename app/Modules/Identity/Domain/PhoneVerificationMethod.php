<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum PhoneVerificationMethod: string
{
    case TelegramContact = 'telegram_contact';
    case SmsOtp = 'sms_otp';
}
