<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\IranianMobileNumber;
use InvalidArgumentException;

final readonly class SmsOtpMessage
{
    public function __construct(
        public IranianMobileNumber $destination,
        public string $code,
        public string $purpose,
        public string $idempotencyKey,
        public ?int $userId = null,
        public ?string $challengeId = null,
        public string $locale = 'fa',
    ) {
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            throw new InvalidArgumentException('OTP code must contain exactly six ASCII digits.');
        }

        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $purpose) !== 1) {
            throw new InvalidArgumentException('OTP purpose is invalid.');
        }

        if (preg_match('/\A[A-Za-z0-9:_-]{16,128}\z/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('SMS idempotency key is invalid.');
        }

        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('SMS user ID must be positive.');
        }

        if ($challengeId !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $challengeId) !== 1) {
            throw new InvalidArgumentException('OTP challenge ID must be a ULID.');
        }

        if (preg_match('/\A[a-z]{2}(?:-[A-Z]{2})?\z/', $locale) !== 1) {
            throw new InvalidArgumentException('SMS locale is invalid.');
        }
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return [
            'destination' => $this->destination->masked(),
            'code' => '[REDACTED]',
            'purpose' => $this->purpose,
            'idempotency_key' => '[REDACTED]',
            'user_id' => $this->userId,
            'challenge_id' => $this->challengeId,
            'locale' => $this->locale,
        ];
    }
}
