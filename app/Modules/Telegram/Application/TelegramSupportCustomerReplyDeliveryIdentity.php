<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final class TelegramSupportCustomerReplyDeliveryIdentity
{
    private const REQUEST_KEY_PREFIX = 'tg-support-customer-reply:';

    public static function requestKey(string $supportOutboxEventId, int $messageId): string
    {
        if ($messageId < 1) {
            throw new InvalidArgumentException('Support customer-reply message ID is invalid.');
        }
        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $supportOutboxEventId,
        ) !== 1) {
            throw new InvalidArgumentException('Support customer-reply Outbox event ID is invalid.');
        }

        return self::REQUEST_KEY_PREFIX.$messageId.':'.$supportOutboxEventId;
    }

    public static function requestKeyHash(string $supportOutboxEventId, int $messageId): string
    {
        return hash('sha256', self::requestKey($supportOutboxEventId, $messageId));
    }
}
