<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramManagedUsdtRateSnapshot
{
    public function __construct(
        public ?int $version,
        public string $rateIrr,
        public string $source,
        public bool $replayed = false,
    ) {}
}
