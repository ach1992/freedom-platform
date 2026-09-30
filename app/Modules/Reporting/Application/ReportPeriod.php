<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final class ReportPeriod
{
    public const TODAY = 'today';

    public const YESTERDAY = 'yesterday';

    public const LAST_7_DAYS = 'last_7_days';

    public const LAST_30_DAYS = 'last_30_days';

    public const CURRENT_WEEK = 'current_week';

    public const CURRENT_MONTH = 'current_month';

    public const PERSIAN_MONTH = 'persian_month';

    public const PREVIOUS_PERSIAN_MONTH = 'previous_persian_month';

    public const LAST_3_MONTHS = 'last_3_months';

    public const LAST_6_MONTHS = 'last_6_months';

    public const LAST_YEAR = 'last_year';

    public const ALL_TIME = 'all_time';

    public const CUSTOM = 'custom';

    /** @return list<string> */
    public static function presets(): array
    {
        return [
            self::TODAY,
            self::YESTERDAY,
            self::LAST_7_DAYS,
            self::LAST_30_DAYS,
            self::CURRENT_WEEK,
            self::CURRENT_MONTH,
            self::PERSIAN_MONTH,
            self::PREVIOUS_PERSIAN_MONTH,
            self::LAST_3_MONTHS,
            self::LAST_6_MONTHS,
            self::LAST_YEAR,
            self::ALL_TIME,
        ];
    }

    private function __construct() {}
}
