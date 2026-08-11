<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Modules\Identity\Application\Contracts\SmsProvider;
use InvalidArgumentException;

final readonly class FallbackSmsDispatcher
{
    /** @requirement ONB-004 SEC-003 INT-002 */
    public function __construct(
        private SmsProvider $primary,
        private SmsProvider $fallback,
        private SmsDeliveryAttemptRecorder $recorder,
    ) {
        if ($primary->code() === $fallback->code()) {
            throw new InvalidArgumentException('Primary and fallback SMS providers must differ.');
        }
    }

    public function dispatch(SmsOtpMessage $message): SmsDispatchResult
    {
        $attempts = [];
        $primaryAttempt = new SmsDeliveryAttempt($this->primary->code(), $this->primary->sendOtp($message));
        $attempts[] = $primaryAttempt;
        $this->recorder->record($message, $primaryAttempt, 1);

        if (! $primaryAttempt->result->status->allowsFallback()) {
            return new SmsDispatchResult($attempts);
        }

        $fallbackAttempt = new SmsDeliveryAttempt($this->fallback->code(), $this->fallback->sendOtp($message));
        $attempts[] = $fallbackAttempt;
        $this->recorder->record($message, $fallbackAttempt, 2);

        return new SmsDispatchResult($attempts);
    }
}
