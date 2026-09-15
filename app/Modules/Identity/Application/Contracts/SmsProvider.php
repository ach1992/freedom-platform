<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Application\SmsOtpMessage;

interface SmsProvider
{
    public function code(): string;

    public function sendOtp(SmsOtpMessage $message): SmsDeliveryResult;
}
