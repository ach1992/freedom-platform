<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramOwnedServiceAction: string
{
    case Renew = 'renew';
    case AddData = 'add_data';
    case AddDays = 'add_days';
    case AddDataDays = 'add_data_days';
    case ResetUsage = 'reset_usage';
    case Suspend = 'suspend';
    case Activate = 'activate';
    case RotateSubscriptionLink = 'rotate_subscription_link';
    case RefreshDetails = 'refresh_details';
    case Delete = 'delete';

    public function isLifecycleAction(): bool
    {
        return in_array($this, [
            self::ResetUsage, self::Suspend, self::Activate, self::RotateSubscriptionLink,
            self::RefreshDetails, self::Delete,
        ], true);
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::Renew,
            self::AddData,
            self::AddDays,
            self::AddDataDays,
            self::ResetUsage,
            self::Suspend,
            self::Activate,
            self::RotateSubscriptionLink,
            self::RefreshDetails,
            self::Delete,
        ];
    }
}
