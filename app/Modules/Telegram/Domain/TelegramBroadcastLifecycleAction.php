<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramBroadcastLifecycleAction: string
{
    case Edit = 'edit';
    case Buttons = 'buttons';
    case Pin = 'pin';
    case Unpin = 'unpin';
    case Delete = 'delete';

    public function successfulRecipientState(): string
    {
        return match ($this) {
            self::Edit => 'edited',
            self::Buttons => 'buttons',
            self::Pin => 'pinned',
            self::Unpin => 'unpinned',
            self::Delete => 'deleted',
        };
    }
}
