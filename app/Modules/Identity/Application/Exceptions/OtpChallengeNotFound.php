<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class OtpChallengeNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('OTP challenge was not found for this account.');
    }
}
