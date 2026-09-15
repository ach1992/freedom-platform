<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Application\SmsOtpMessage;

interface SmsOtpMessageRenderer
{
    public function render(SmsOtpMessage $message): string;
}
