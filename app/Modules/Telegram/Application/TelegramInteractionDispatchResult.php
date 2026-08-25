<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionDispatchStatus;

final readonly class TelegramInteractionDispatchResult
{
    public function __construct(
        public TelegramInteractionDispatchStatus $status,
        public ?string $sessionPublicId = null,
        public ?string $callbackPublicId = null,
    ) {}
}
