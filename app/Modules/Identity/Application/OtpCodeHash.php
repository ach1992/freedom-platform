<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use InvalidArgumentException;

final readonly class OtpCodeHash
{
    public function __construct(
        public string $value,
        public int $keyVersion,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InvalidArgumentException('OTP code hash must be lowercase SHA-256 hexadecimal.');
        }

        if ($keyVersion < 1) {
            throw new InvalidArgumentException('OTP code hash key version must be positive.');
        }
    }
}
