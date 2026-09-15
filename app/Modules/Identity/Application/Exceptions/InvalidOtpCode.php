<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class InvalidOtpCode extends RuntimeException
{
    public function __construct(public readonly int $remainingAttempts)
    {
        parent::__construct('OTP code is invalid.');
    }
}
