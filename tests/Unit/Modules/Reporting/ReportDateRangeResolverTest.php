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
        $priorToday = $today->prior();
        self::assertNotNull($priorToday);
        self::assertSame('2026-09-29 14:13:00.000000', $priorToday->databaseStart());
        self::assertSame('2026-09-29 20:30:00.000000', $priorToday->databaseEndExclusive());

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

    public function test_historical_tehran_dst_transition_is_resolved_by_timezone_rules_then_stored_as_utc(): void
    {
        $resolver = new ReportDateRangeResolver($this->clock('2026-09-30T00:00:00+00:00'));

        $range = $resolver->resolveAt(
            ReportPeriod::TODAY,
            new DateTimeImmutable('2021-03-22T07:30:00+00:00'),
        );

        // Tehran moved from +03:30 to +04:30 at the historical spring transition.
        // The local day boundary is resolved by the timezone database, then persisted/query-bound in UTC.
        self::assertSame('2021-03-21 20:30:00.000000', $range->databaseStart());
        self::assertSame('2021-03-22 07:30:00.000000', $range->databaseEndExclusive());

    }

    public function test_all_required_presets_and_custom_range_have_deterministic_utc_boundaries(): void
    {
        $resolver = new ReportDateRangeResolver($this->clock('2026-09-30T02:47:00+00:00'));

        $expectations = [
            ReportPeriod::TODAY => ['2026-09-29 20:30:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::YESTERDAY => ['2026-09-28 20:30:00.000000', '2026-09-29 20:30:00.000000'],
            ReportPeriod::LAST_7_DAYS => ['2026-09-23 20:30:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::LAST_30_DAYS => ['2026-08-31 20:30:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::CURRENT_WEEK => ['2026-09-25 20:30:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::CURRENT_MONTH => ['2026-08-31 20:30:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::PERSIAN_MONTH => ['2026-09-22 20:30:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::PREVIOUS_PERSIAN_MONTH => ['2026-08-22 20:30:00.000000', '2026-09-22 20:30:00.000000'],
            ReportPeriod::LAST_3_MONTHS => ['2026-06-30 02:47:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::LAST_6_MONTHS => ['2026-03-30 02:47:00.000000', '2026-09-30 02:47:00.000000'],
            ReportPeriod::LAST_YEAR => ['2025-09-30 02:47:00.000000', '2026-09-30 02:47:00.000000'],
        ];

        foreach ($expectations as $period => [$expectedStart, $expectedEnd]) {
            $range = $resolver->resolve($period);
            self::assertSame($expectedStart, $range->databaseStart(), $period.' start');
            self::assertSame($expectedEnd, $range->databaseEndExclusive(), $period.' end');
        }

        $allTime = $resolver->resolve(ReportPeriod::ALL_TIME);
        self::assertNull($allTime->databaseStart());
        self::assertSame('2026-09-30 02:47:00.000000', $allTime->databaseEndExclusive());

        $custom = $resolver->resolve(
            ReportPeriod::CUSTOM,
            new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-09-15T00:00:00+00:00'),
        );
        self::assertSame('2026-09-01 00:00:00.000000', $custom->databaseStart());
        self::assertSame('2026-09-15 00:00:00.000000', $custom->databaseEndExclusive());
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
