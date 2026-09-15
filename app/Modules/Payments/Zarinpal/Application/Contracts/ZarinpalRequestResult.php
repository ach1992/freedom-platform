<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application\Contracts;

final readonly class ZarinpalRequestResult
{
    private function __construct(
        public bool $accepted,
        public bool $uncertain,
        public ?string $authority,
        public ?int $providerCode,
    ) {}

    public static function accepted(string $authority, int $providerCode = 100): self
    {
        return new self(true, false, $authority, $providerCode);
    }

    public static function rejected(?int $providerCode = null): self
    {
        return new self(false, false, null, $providerCode);
    }

    public static function uncertain(): self
    {
        return new self(false, true, null, null);
    }
}
