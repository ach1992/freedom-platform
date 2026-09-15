<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\OtpCodeHasher;
use App\Modules\Identity\Application\OtpCodeHash;
use InvalidArgumentException;

final readonly class HmacOtpCodeHasher implements OtpCodeHasher
{
    public function __construct(
        private string $key,
        private int $keyVersion,
    ) {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('OTP HMAC key must contain at least 32 bytes.');
        }

        if ($keyVersion < 1) {
            throw new InvalidArgumentException('OTP HMAC key version must be positive.');
        }
    }

    public function hash(string $challengeId, string $code): OtpCodeHash
    {
        $this->assertInput($challengeId, $code);

        return new OtpCodeHash(
            hash_hmac('sha256', $challengeId."\0".$code, $this->key),
            $this->keyVersion,
        );
    }

    public function verify(string $challengeId, string $code, string $expectedHash): bool
    {
        $this->assertInput($challengeId, $code);

        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1) {
            return false;
        }

        return hash_equals($expectedHash, $this->hash($challengeId, $code)->value);
    }

    private function assertInput(string $challengeId, string $code): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $challengeId) !== 1) {
            throw new InvalidArgumentException('OTP challenge ID must be a ULID.');
        }

        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            throw new InvalidArgumentException('OTP code must contain exactly six ASCII digits.');
        }
    }
}
