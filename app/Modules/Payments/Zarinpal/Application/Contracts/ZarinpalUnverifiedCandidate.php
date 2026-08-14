<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application\Contracts;

final readonly class ZarinpalUnverifiedCandidate
{
    public function __construct(
        public string $authority,
        public int $amount,
        public string $callbackUrl,
        public string $providerDate,
    ) {}
}
