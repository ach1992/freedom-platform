<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application\Contracts;

final readonly class ZarinpalInquiryResult
{
    private const ALLOWED_STATUSES = ['VERIFIED', 'PAID', 'IN_BANK', 'FAILED', 'REVERSED'];

    private function __construct(
        public bool $available,
        public ?string $status,
        public ?int $providerCode,
    ) {}

    public static function available(string $status, int $providerCode = 100): self
    {
        $normalized = strtoupper($status);
        if (! in_array($normalized, self::ALLOWED_STATUSES, true)) {
            return new self(false, null, $providerCode);
        }

        return new self(true, $normalized, $providerCode);
    }

    public static function unavailable(?int $providerCode = null): self
    {
        return new self(false, null, $providerCode);
    }
}
