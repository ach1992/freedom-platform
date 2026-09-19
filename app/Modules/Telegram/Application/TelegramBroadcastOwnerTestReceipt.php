<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramBroadcastOwnerTestReceipt
{
    public function __construct(
        public string $publicId,
        public string $state,
        public bool $replayed,
        public ?string $deliveryOperationPublicId = null,
        public ?int $messageId = null,
        public ?string $resultCode = null,
    ) {}
}
