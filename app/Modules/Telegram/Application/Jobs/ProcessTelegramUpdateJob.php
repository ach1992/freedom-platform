<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Jobs;

use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 300];

    /** @requirement ONB-001 PAY-003 SEC-009 OPS-003 */
    public function __construct(
        public readonly string $botId,
        public readonly int $updateId,
    ) {
    }

    public function handle(TelegramUpdateProcessor $processor): void
    {
        $processor->process($this->botId, $this->updateId);
    }
}
