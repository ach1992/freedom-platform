<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramInteractionPolicy
{
    public function __construct(
        public int $sessionTtlSeconds,
        public int $callbackTtlSeconds,
    ) {
        if ($sessionTtlSeconds < 60 || $sessionTtlSeconds > 86_400) {
            throw new InvalidArgumentException('Telegram interaction session TTL must be between 60 seconds and 24 hours.');
        }

        if ($callbackTtlSeconds < 30 || $callbackTtlSeconds > $sessionTtlSeconds) {
            throw new InvalidArgumentException('Telegram interaction callback TTL must be between 30 seconds and the session TTL.');
        }
    }
}
