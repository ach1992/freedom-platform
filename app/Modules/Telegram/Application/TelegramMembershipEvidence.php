<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramMembershipEvidence: string
{
    case Member = 'member';
    case NotMember = 'not_member';
    case Unavailable = 'unavailable';
}
