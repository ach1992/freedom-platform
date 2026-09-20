<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramBroadcastMessageMode: string
{
    case NewText = 'new_text';
    case Copy = 'copy';
    case Forward = 'forward';

    public function supportsAuthoredInlineKeyboard(): bool
    {
        return $this !== self::Forward;
    }

    public function supportsTextEdit(): bool
    {
        return $this === self::NewText;
    }
}
