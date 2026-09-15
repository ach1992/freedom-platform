<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum CustomPlanUsernameMode: string
{
    case TelegramUserId = 'telegram_user_id';
    case TelegramUserIdSuffix = 'telegram_user_id_suffix';
    case CustomerSelected = 'customer_selected';
}
