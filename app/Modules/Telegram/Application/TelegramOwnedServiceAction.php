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

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::Renew,
            self::AddData,
            self::AddDays,
            self::AddDataDays,
            self::ResetUsage,
        ];
    }
}
