<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final class TelegramDeliveryOutboxContract
{
    public const EVENT_TYPE = 'telegram.delivery.requested';

    public const CONTRACT_VERSION_CONFIDENTIAL = 3;

    public const AGGREGATE_TYPE = 'telegram_delivery_operation';

    public const EVENT_KEY_PREFIX = 'telegram-delivery-requested:';
}
