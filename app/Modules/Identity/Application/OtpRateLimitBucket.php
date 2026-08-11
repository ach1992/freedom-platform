<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use InvalidArgumentException;

final readonly class OtpRateLimitBucket
{
    public function __construct(
        public string $name,
        public string $key,
        public int $limit,
        public int $windowSeconds,
    ) {
        if (preg_match('/\A[a-z0-9_.-]{2,64}\z/', $name) !== 1) {
            throw new InvalidArgumentException('OTP rate-limit bucket name is invalid.');
        }

        if ($key === '' || strlen($key) > 512) {
            throw new InvalidArgumentException('OTP rate-limit bucket key is invalid.');
        }

        if ($limit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('OTP rate-limit values must be positive.');
        }
    }
}
