<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reporting;

use App\Modules\Reporting\Application\ReportDateRangeResolver;
use App\Modules\Reporting\Application\ReportPeriod;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReportDateRangeResolverTest extends TestCase
{
    public function test_tehran_day_week_month_and_prior_ranges_are_utc_half_open(): void
    {
        $resolver = new ReportDateRangeResolver($this->clock('2026-09-30T06:17:00+03:30'));

        $today = $resolver->resolve(ReportPeriod::TODAY);
        self::assertSame('2026-09-29 20:30:00.000000', $today->databaseStart());
        self::assertSame('2026-09-30 02:47:00.000000', $today->databaseEndExclusive());
        self::assertSame('2026-09-29 14:13:00.000000', $today->prior()?->databaseStart());
        self::assertSame('2026-09-29 20:30:00.000000', $today->prior()?->databaseEndExclusive());

        $week = $resolver->resolve(ReportPeriod::CURRENT_WEEK);
        self::assertSame('2026-09-25 20:30:00.000000', $week->databaseStart()); // Saturday 2026-09-26 Tehran.

        $month = $resolver->resolve(ReportPeriod::CURRENT_MONTH);
        self::assertSame('2026-08-31 20:30:00.000000', $month->databaseStart());
    }

    public function test_persian_month_boundaries_are_converted_without_changing_utc_authority(): void
    {
        $resolver = new ReportDateRangeResolver($this->clock('2026-09-30T06:17:00+03:30'));

        $current = $resolver->resolve(ReportPeriod::PERSIAN_MONTH);
        $previous = $resolver->resolve(ReportPeriod::PREVIOUS_PERSIAN_MONTH);

        // 1405-07-01 and 1405-06-01 at Tehran midnight.
        self::assertSame('2026-09-22 20:30:00.000000', $current->databaseStart());
        self::assertSame('2026-08-22 20:30:00.000000', $previous->databaseStart());
        self::assertSame('2026-09-22 20:30:00.000000', $previous->databaseEndExclusive());
    }

    private function clock(string $now): Clock
    {
        return new class(new DateTimeImmutable($now)) implements Clock
        {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
