<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use DateTimeImmutable;

final readonly class TelegramBroadcastCampaignReceipt
{
    public function __construct(
        public string $publicId,
        public TelegramBroadcastCampaignState $state,
        public int $stateVersion,
        public TelegramBroadcastMessageMode $messageMode,
        public int $messageVersion,
        public int $audienceVersion,
        public int $estimatedRecipientCount,
        public int $recipientCount,
        public ?DateTimeImmutable $scheduledAt,
        public bool $replayed,
    ) {}
}
