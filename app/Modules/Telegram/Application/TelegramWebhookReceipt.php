<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramWebhookReceipt
{
    public function __construct(
        public string $botId,
        public int $updateId,
        public bool $duplicate,
    ) {}
}
