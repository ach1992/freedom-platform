<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramServiceAutoRenewPolicyResult
{
    public function __construct(
        public string $offeringCode,
        public string $mode,
        public ?int $absoluteIncreaseLimitIrr,
        public ?int $percentageIncreaseLimitBps,
        public int $version,
        public bool $replayed,
    ) {}
}
