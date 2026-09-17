<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

enum SupportTicketSearchField: string
{
    case Tracking = 'tracking';
    case User = 'user';
    case Order = 'order';
    case Payment = 'payment';
    case Service = 'service';

    public function column(): string
    {
        return match ($this) {
            self::Tracking => 'tracking_number',
            self::User => 'requester_user_id',
            self::Order => 'order_id',
            self::Payment => 'payment_intent_id',
            self::Service => 'service_subscription_id',
        };
    }

    public function isNumeric(): bool
    {
        return $this !== self::Tracking;
    }
}
