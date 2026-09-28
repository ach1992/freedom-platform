<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final class SupportCustomerReplyNotification
{
    public const EVENT_TYPE = 'support.customer_reply.notification_requested';

    public const CONTRACT_VERSION = 1;

    public const AGGREGATE_TYPE = 'support_ticket_message';

    public static function eventKey(int $messageId): string
    {
        return 'support-customer-reply-notification:'.$messageId;
    }

    public static function correlationId(int $messageId): string
    {
        return 'support.reply.'.$messageId;
    }

    public static function deduplicationKey(int $messageId): string
    {
        return hash('sha256', 'support-customer-reply-delivery:'.$messageId);
    }
}
