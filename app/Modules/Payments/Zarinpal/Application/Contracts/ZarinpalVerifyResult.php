<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application\Contracts;

final readonly class ZarinpalVerifyResult
{
    private function __construct(
        public bool $verified,
        public bool $uncertain,
        public ?int $providerCode,
        public ?string $refId,
    ) {}

    public static function verified(string $refId, int $providerCode): self
    {
        return new self(true, false, $providerCode, $refId);
    }

    public static function rejected(?int $providerCode = null): self
    {
        return new self(false, false, $providerCode, null);
    }

    public static function uncertain(): self
    {
        return new self(false, true, null, null);
    }
}
