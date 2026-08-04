<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramWebhookInfo
{
    public function __construct(
        public bool $configured,
        public bool $targetsExpectedUrl,
        public int $pendingUpdateCount,
        public bool $lastErrorPresent,
    ) {
    }
}
