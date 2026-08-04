<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use DateTimeImmutable;
use RuntimeException;

final class OtpResendCooldownActive extends RuntimeException
{
    public function __construct(public readonly DateTimeImmutable $availableAt)
    {
        parent::__construct('OTP resend cooldown is active.');
    }
}
