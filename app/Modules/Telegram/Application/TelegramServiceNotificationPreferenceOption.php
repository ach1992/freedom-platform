<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramServiceNotificationPreferenceOption
{
    public function __construct(
        public string $notificationType,
        public string $thresholdCode,
        public bool $enabled,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $notificationType) !== 1
            || ($thresholdCode !== '*' && preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $thresholdCode) !== 1)) {
            throw new InvalidArgumentException('Telegram Service notification preference option is invalid.');
        }
    }
}
