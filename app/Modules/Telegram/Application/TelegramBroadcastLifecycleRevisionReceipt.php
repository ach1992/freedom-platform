<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramBroadcastLifecycleRevisionReceipt
{
    public function __construct(
        public string $campaignPublicId,
        public int $stateVersion,
        public int $messageVersion,
        public bool $replayed,
    ) {}
}
