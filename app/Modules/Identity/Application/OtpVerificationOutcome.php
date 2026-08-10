<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use InvalidArgumentException;

final readonly class OtpVerificationOutcome
{
    private function __construct(
        public ?OtpVerificationReceipt $receipt,
        public ?string $failure,
        public ?int $remainingAttempts,
    ) {}

    public static function verified(OtpVerificationReceipt $receipt): self
    {
        return new self($receipt, null, null);
    }

    public static function expired(): self
    {
        return new self(null, 'expired', null);
    }

    public static function inactive(): self
    {
        return new self(null, 'inactive', null);
    }

    public static function invalidCode(int $remainingAttempts): self
    {
        if ($remainingAttempts < 0) {
            throw new InvalidArgumentException('Remaining OTP attempts cannot be negative.');
        }

        return new self(null, 'invalid_code', $remainingAttempts);
    }
}
