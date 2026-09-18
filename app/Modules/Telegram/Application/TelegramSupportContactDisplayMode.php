<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramSupportContactDisplayMode: string
{
    case Internal = 'internal';
    case External = 'external';
    case Both = 'both';

    public function showsInternal(): bool
    {
        return $this !== self::External;
    }

    public function showsExternal(): bool
    {
        return $this !== self::Internal;
    }
}
