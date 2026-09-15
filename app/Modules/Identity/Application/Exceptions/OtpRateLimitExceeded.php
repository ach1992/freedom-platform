<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class OtpRateLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $bucketName)
    {
        parent::__construct('OTP rate limit exceeded for bucket: '.$bucketName);
    }
}
