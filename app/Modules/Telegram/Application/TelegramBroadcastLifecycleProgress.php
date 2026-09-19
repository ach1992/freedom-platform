<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;

final readonly class TelegramBroadcastLifecycleProgress
{
    public function __construct(
        public string $campaignPublicId,
        public string $groupPublicId,
        public TelegramBroadcastLifecycleAction $action,
        public int $recipientCount,
        public int $prepared,
        public int $queued,
        public int $sending,
        public int $succeeded,
        public int $retryable,
        public int $failed,
        public int $uncertain,
        public int $skipped,
    ) {}

    public function finishedCount(): int
    {
        return $this->succeeded + $this->retryable + $this->failed + $this->uncertain + $this->skipped;
    }
}
