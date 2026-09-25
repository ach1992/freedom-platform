<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramServiceNotificationPreferenceResult
{
    public function __construct(
        public string $notificationType,
        public string $thresholdCode,
        public bool $enabled,
        public int $version,
        public bool $replayed,
    ) {}
}
