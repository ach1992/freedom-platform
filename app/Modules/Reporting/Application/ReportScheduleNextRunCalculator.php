<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class ReportScheduleNextRunCalculator
{
    private const BUSINESS_TIMEZONE = 'Asia/Tehran';

    public function next(
        string $frequency,
        string $runTimeLocal,
        ?int $weekdayIso,
        ?int $dayOfMonth,
        DateTimeImmutable $afterUtc,
    ): DateTimeImmutable {
        if (preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $runTimeLocal, $matches) !== 1) {
            throw new DomainException('Report schedule time must use HH:MM.');
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $tehran = new DateTimeZone(self::BUSINESS_TIMEZONE);
        $afterLocal = $afterUtc->setTimezone($tehran);

        $candidate = match ($frequency) {
            ReportScheduleFrequency::DAILY => $this->daily($afterLocal, $hour, $minute, $weekdayIso, $dayOfMonth),
            ReportScheduleFrequency::WEEKLY => $this->weekly($afterLocal, $hour, $minute, $weekdayIso, $dayOfMonth),
            ReportScheduleFrequency::MONTHLY => $this->monthly($afterLocal, $hour, $minute, $weekdayIso, $dayOfMonth),
            default => throw new DomainException('Unsupported report schedule frequency.'),
        };

        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    private function daily(
        DateTimeImmutable $afterLocal,
        int $hour,
        int $minute,
        ?int $weekdayIso,
        ?int $dayOfMonth,
    ): DateTimeImmutable {
        if ($weekdayIso !== null || $dayOfMonth !== null) {
            throw new DomainException('Daily report schedules cannot set weekday or day-of-month.');
        }

        $candidate = $afterLocal->setTime($hour, $minute, 0, 0);

        return $candidate > $afterLocal ? $candidate : $candidate->modify('+1 day');
    }

    private function weekly(
        DateTimeImmutable $afterLocal,
        int $hour,
        int $minute,
        ?int $weekdayIso,
        ?int $dayOfMonth,
    ): DateTimeImmutable {
        if ($weekdayIso === null || $weekdayIso < 1 || $weekdayIso > 7 || $dayOfMonth !== null) {
            throw new DomainException('Weekly report schedules require ISO weekday 1-7 only.');
        }

        $currentIso = (int) $afterLocal->format('N');
        $days = ($weekdayIso - $currentIso + 7) % 7;
        $candidate = $afterLocal->setTime($hour, $minute, 0, 0)->modify('+'.$days.' days');
        if ($candidate <= $afterLocal) {
            $candidate = $candidate->modify('+7 days');
        }

        return $candidate;
    }

    private function monthly(
        DateTimeImmutable $afterLocal,
        int $hour,
        int $minute,
        ?int $weekdayIso,
        ?int $dayOfMonth,
    ): DateTimeImmutable {
        if ($weekdayIso !== null || $dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 28) {
            throw new DomainException('Monthly report schedules require day-of-month 1-28 only.');
        }

        $candidate = $afterLocal
            ->setDate((int) $afterLocal->format('Y'), (int) $afterLocal->format('m'), $dayOfMonth)
            ->setTime($hour, $minute, 0, 0);
        if ($candidate <= $afterLocal) {
            $nextMonth = $afterLocal->modify('first day of next month');
            $candidate = $nextMonth
                ->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('m'), $dayOfMonth)
                ->setTime($hour, $minute, 0, 0);
        }

        return $candidate;
    }
}
