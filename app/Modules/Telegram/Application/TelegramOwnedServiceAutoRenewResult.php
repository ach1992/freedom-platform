<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramOwnedServiceAutoRenewResult
{
    public function __construct(
        public bool $enabled,
        public string $packageCode,
        public int $acceptedPriceIrr,
        public int $configurationVersion,
        public bool $replayed,
    ) {}
}
