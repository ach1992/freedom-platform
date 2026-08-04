<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\OtpAbuseLimiter;
use App\Modules\Identity\Application\Contracts\SmsProvider;
use App\Modules\Identity\Application\OtpRateLimitBucket;
use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Application\SmsOtpMessage;
use InvalidArgumentException;

final readonly class RateLimitedSmsProvider implements SmsProvider
{
    public function __construct(
        private SmsProvider $provider,
        private OtpAbuseLimiter $limiter,
        private int $dailyLimit,
    ) {
        if ($dailyLimit < 1) {
            throw new InvalidArgumentException('SMS provider daily limit must be positive.');
        }
    }

    public function code(): string
    {
        return $this->provider->code();
    }

    public function sendOtp(SmsOtpMessage $message): SmsDeliveryResult
    {
        $this->limiter->consume([
            new OtpRateLimitBucket('provider', $this->code(), $this->dailyLimit, 86400),
        ]);

        return $this->provider->sendOtp($message);
    }
}
