<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

interface TelegramAgentReport
{
    public const PERIOD_TODAY = 'today';

    public const PERIOD_SEVEN_DAYS = '7d';

    public const PERIOD_THIRTY_DAYS = '30d';

    public const PERIOD_ALL = 'all';

    /** @var list<string> */
    public const PERIODS = [
        self::PERIOD_TODAY,
        self::PERIOD_SEVEN_DAYS,
        self::PERIOD_THIRTY_DAYS,
        self::PERIOD_ALL,
    ];

    /** @requirement AGT-006 SEC-003 */
    public function forSelf(int $actorUserId, int $subjectUserId, string $period): TelegramAgentReportSnapshot;
}
