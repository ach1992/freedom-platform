<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Application\SmsDeliveryAttempt;
use App\Modules\Identity\Application\SmsOtpMessage;

interface SmsDeliveryAttemptRecorder
{
    public function record(SmsOtpMessage $message, SmsDeliveryAttempt $attempt, int $sequence): void;
}
