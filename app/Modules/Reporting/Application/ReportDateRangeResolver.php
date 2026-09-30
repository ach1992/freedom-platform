<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use IntlCalendar;
use IntlTimeZone;
use RuntimeException;

final readonly class ReportDateRangeResolver
{
    private const BUSINESS_TIMEZONE = 'Asia/Tehran';

    public function __construct(private Clock $clock) {}

    public function resolve(
        string $period,
        ?DateTimeImmutable $customStartUtc = null,
        ?DateTimeImmutable $customEndUtc = null,
    ): ReportDateRange {
        $utc = new DateTimeZone('UTC');
        $nowUtc = $this->clock->now()->setTimezone($utc);
        $tehran = new DateTimeZone(self::BUSINESS_TIMEZONE);
        $nowLocal = $nowUtc->setTimezone($tehran);

        if ($period === ReportPeriod::CUSTOM) {
            if ($customStartUtc === null || $customEndUtc === null) {
                throw new DomainException('Custom report ranges require both UTC boundaries.');
            }

            $start = $customStartUtc->setTimezone($utc);
            $end = $customEndUtc->setTimezone($utc);
            if ($end > $nowUtc) {
                throw new DomainException('Custom report ranges cannot end in the future.');
            }

            return new ReportDateRange($period, $start, $end);
        }

        if ($customStartUtc !== null || $customEndUtc !== null) {
            throw new DomainException('Custom report boundaries are only valid with the custom period.');
        }

        $range = match ($period) {
            ReportPeriod::TODAY => [$this->midnight($nowLocal), $nowLocal],
            ReportPeriod::YESTERDAY => [
                $this->midnight($nowLocal)->modify('-1 day'),
                $this->midnight($nowLocal),
            ],
            ReportPeriod::LAST_7_DAYS => [$this->midnight($nowLocal)->modify('-6 days'), $nowLocal],
            ReportPeriod::LAST_30_DAYS => [$this->midnight($nowLocal)->modify('-29 days'), $nowLocal],
            ReportPeriod::CURRENT_WEEK => [$this->startOfBusinessWeek($nowLocal), $nowLocal],
            ReportPeriod::CURRENT_MONTH => [$this->startOfGregorianMonth($nowLocal), $nowLocal],
            ReportPeriod::PERSIAN_MONTH => [$this->startOfPersianMonth($nowLocal, 0), $nowLocal],
            ReportPeriod::PREVIOUS_PERSIAN_MONTH => [
                $this->startOfPersianMonth($nowLocal, -1),
                $this->startOfPersianMonth($nowLocal, 0),
            ],
            ReportPeriod::LAST_3_MONTHS => [$nowLocal->modify('-3 months'), $nowLocal],
            ReportPeriod::LAST_6_MONTHS => [$nowLocal->modify('-6 months'), $nowLocal],
            ReportPeriod::LAST_YEAR => [$nowLocal->modify('-1 year'), $nowLocal],
            ReportPeriod::ALL_TIME => [null, $nowLocal],
            default => throw new DomainException('Unsupported report period.'),
        };

        return new ReportDateRange(
            $period,
            $range[0]?->setTimezone($utc),
            $range[1]->setTimezone($utc),
        );
    }

    private function midnight(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTime(0, 0, 0, 0);
    }

    private function startOfBusinessWeek(DateTimeImmutable $value): DateTimeImmutable
    {
        $midnight = $this->midnight($value);
        $daysSinceSaturday = (((int) $midnight->format('w')) + 1) % 7;

        return $daysSinceSaturday === 0 ? $midnight : $midnight->modify('-'.$daysSinceSaturday.' days');
    }

    private function startOfGregorianMonth(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->modify('first day of this month')->setTime(0, 0, 0, 0);
    }

    private function startOfPersianMonth(DateTimeImmutable $value, int $monthOffset): DateTimeImmutable
    {
        $timezone = IntlTimeZone::createTimeZone(self::BUSINESS_TIMEZONE);
        $calendar = IntlCalendar::createInstance($timezone, 'fa_IR@calendar=persian');
        if (! $calendar instanceof IntlCalendar) {
            throw new RuntimeException('Persian calendar support is unavailable.');
        }

        $calendar->setTime($value->getTimestamp() * 1000.0);
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $calendar->set(IntlCalendar::FIELD_HOUR_OF_DAY, 0);
        $calendar->set(IntlCalendar::FIELD_MINUTE, 0);
        $calendar->set(IntlCalendar::FIELD_SECOND, 0);
        $calendar->set(IntlCalendar::FIELD_MILLISECOND, 0);
        if ($monthOffset !== 0 && ! $calendar->add(IntlCalendar::FIELD_MONTH, $monthOffset)) {
            throw new RuntimeException('Persian calendar month offset failed.');
        }

        $milliseconds = $calendar->getTime();
        if (! is_float($milliseconds)) {
            throw new RuntimeException('Persian calendar conversion failed.');
        }

        return (new DateTimeImmutable('@'.(string) intdiv((int) round($milliseconds), 1000)))
            ->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
    }
}
