<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reporting;

use App\Modules\Reporting\Application\ReportScheduleFrequency;
use App\Modules\Reporting\Application\ReportScheduleNextRunCalculator;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ReportScheduleNextRunCalculatorTest extends TestCase
{
    public function test_daily_weekly_and_monthly_times_are_tehran_local_but_persist_as_utc(): void
    {
        $calculator = new ReportScheduleNextRunCalculator;
        $after = new DateTimeImmutable('2026-09-30T02:47:00+00:00'); // 06:17 Tehran, Wednesday.

        self::assertSame(
            '2026-09-30T04:30:00+00:00',
            $calculator->next(ReportScheduleFrequency::DAILY, '08:00', null, null, $after)->format('c'),
        );
        self::assertSame(
            '2026-10-03T04:30:00+00:00',
            $calculator->next(ReportScheduleFrequency::WEEKLY, '08:00', 6, null, $after)->format('c'), // Saturday.
        );
        self::assertSame(
            '2026-10-15T04:30:00+00:00',
            $calculator->next(ReportScheduleFrequency::MONTHLY, '08:00', null, 15, $after)->format('c'),
        );
    }

    public function test_invalid_schedule_shapes_fail_closed(): void
    {
        $calculator = new ReportScheduleNextRunCalculator;
        $after = new DateTimeImmutable('2026-09-30T02:47:00+00:00');
        $operations = [
            fn () => $calculator->next(ReportScheduleFrequency::DAILY, '8:00', null, null, $after),
            fn () => $calculator->next(ReportScheduleFrequency::DAILY, '08:00', 6, null, $after),
            fn () => $calculator->next(ReportScheduleFrequency::WEEKLY, '08:00', null, null, $after),
            fn () => $calculator->next(ReportScheduleFrequency::MONTHLY, '08:00', null, 29, $after),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid schedule must be rejected.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
