<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final class ReportScheduleFrequency
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::DAILY, self::WEEKLY, self::MONTHLY];
    }

    private function __construct() {}
}
