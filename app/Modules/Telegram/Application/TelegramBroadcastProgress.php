<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;

final readonly class TelegramBroadcastProgress
{
    public function __construct(
        public string $campaignPublicId,
        public TelegramBroadcastCampaignState $state,
        public int $stateVersion,
        public int $recipientCount,
        public int $queued,
        public int $sending,
        public int $sent,
        public int $failedTransient,
        public int $failedPermanent,
        public int $skipped,
        public int $uncertain,
    ) {}

    public function finishedCount(): int
    {
        return $this->sent + $this->failedTransient + $this->failedPermanent + $this->skipped + $this->uncertain;
    }
}
