<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class OtpChallengeExpired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('OTP challenge has expired.');
    }
}
