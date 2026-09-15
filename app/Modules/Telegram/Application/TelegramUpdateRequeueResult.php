<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramUpdateRequeueResult
{
    public function __construct(
        public int $requeued,
        public string $queue,
    ) {}
}
