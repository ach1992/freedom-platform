<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use InvalidArgumentException;

final readonly class OtpIssueRequest
{
    public function __construct(
        public int $userId,
        public int $telegramAccountId,
        public IranianMobileNumber $number,
        public PhoneVerificationPolicy $policy,
        public int $policyVersion,
        public string $purpose,
        public string $requestIp,
        public string $idempotencyKey,
        public ?string $correlationId = null,
    ) {
        if ($userId < 1 || $telegramAccountId < 1) {
            throw new InvalidArgumentException('OTP issue identity IDs must be positive.');
        }

        if ($policyVersion < 1) {
            throw new InvalidArgumentException('OTP policy version must be positive.');
        }

        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $purpose) !== 1) {
            throw new InvalidArgumentException('OTP purpose is invalid.');
        }

        if (filter_var($requestIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('OTP request IP is invalid.');
        }

        if (preg_match('/\A[A-Za-z0-9:_-]{16,128}\z/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('OTP idempotency key is invalid.');
        }

        if ($correlationId !== null && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('OTP correlation ID is invalid.');
        }
    }
}
