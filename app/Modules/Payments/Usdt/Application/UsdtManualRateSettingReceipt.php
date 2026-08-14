<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use DateTimeImmutable;

final readonly class UsdtManualRateSettingReceipt
{
    public function __construct(
        public ?int $version,
        public string $rateIrr,
        public string $source,
        public ?int $createdByAdministratorId,
        public ?DateTimeImmutable $createdAt,
        public bool $replayed = false,
    ) {}
}
