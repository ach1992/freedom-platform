<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;

final readonly class TelegramDeliveryOperationReceipt
{
    public function __construct(
        public string $publicId,
        public TelegramDeliveryAction $action,
        public TelegramDeliveryOperationState $state,
        public string $outboxEventId,
        public bool $replayed,
        public ?int $messageId = null,
        public ?string $resultCode = null,
        public ?int $retryAfterSeconds = null,
    ) {}
}
