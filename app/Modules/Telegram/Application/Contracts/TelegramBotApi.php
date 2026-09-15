<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramWebhookInfo;

interface TelegramBotApi
{
    public function configureWebhook(string $url, string $secretToken, bool $dropPendingUpdates): TelegramWebhookInfo;

    public function webhookInfo(): TelegramWebhookInfo;
}
