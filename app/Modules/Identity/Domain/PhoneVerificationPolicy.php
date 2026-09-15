<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum PhoneVerificationPolicy: string
{
    case None = 'none';
    case TelegramContactOnly = 'telegram_contact_only';
    case SmsOtpOnly = 'sms_otp_only';
    case Either = 'either';
    case Both = 'both';

    public function accepts(PhoneVerificationMethod $method): bool
    {
        return match ($this) {
            self::None => false,
            self::TelegramContactOnly => $method === PhoneVerificationMethod::TelegramContact,
            self::SmsOtpOnly => $method === PhoneVerificationMethod::SmsOtp,
            self::Either, self::Both => true,
        };
    }

    public function isSatisfied(bool $telegramContactVerified, bool $smsOtpVerified): bool
    {
        return match ($this) {
            self::None => true,
            self::TelegramContactOnly => $telegramContactVerified,
            self::SmsOtpOnly => $smsOtpVerified,
            self::Either => $telegramContactVerified || $smsOtpVerified,
            self::Both => $telegramContactVerified && $smsOtpVerified,
        };
    }
}
