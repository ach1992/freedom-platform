<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Domain;

use DateTimeImmutable;

final readonly class UsdtRate
{
    public function __construct(
        public string $source,
        public string $rateIrr,
        public DateTimeImmutable $fetchedAt,
        public string $responseHash,
    ) {}
}
