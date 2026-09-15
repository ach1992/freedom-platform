<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Domain;

enum TelegramInteractionActionKind: string
{
    case Message = 'message';
    case Back = 'back';
    case Callback = 'callback';
}
