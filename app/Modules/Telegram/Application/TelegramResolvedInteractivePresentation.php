<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramResolvedInteractivePresentation
{
    public function __construct(
        public TelegramResolvedInlineKeyboardMarkup $keyboard,
        public string $snapshotHash,
    ) {}

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'interactive_presentation'];
    }
}
