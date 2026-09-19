<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramBroadcastSourceKind: string
{
    case Text = 'text';
    case Photo = 'photo';
    case Video = 'video';
    case Animation = 'animation';
    case Audio = 'audio';
    case Document = 'document';

    public function supportsCaption(): bool
    {
        return $this !== self::Text;
    }
}
