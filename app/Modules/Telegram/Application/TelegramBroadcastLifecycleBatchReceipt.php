<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;

final readonly class TelegramBroadcastLifecycleBatchReceipt
{
    public function __construct(
        public string $campaignPublicId,
        public string $groupPublicId,
        public TelegramBroadcastLifecycleAction $action,
        public int $recipientCount,
        public int $stateVersion,
    ) {}
}
