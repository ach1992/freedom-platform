<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final readonly class ReportDateRange
{
    public ?DateTimeImmutable $startsAtUtc;

    public DateTimeImmutable $endsBeforeUtc;

    public function __construct(
        public string $code,
        ?DateTimeImmutable $startsAtUtc,
        DateTimeImmutable $endsBeforeUtc,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->startsAtUtc = $startsAtUtc?->setTimezone($utc);
        $this->endsBeforeUtc = $endsBeforeUtc->setTimezone($utc);

        if ($this->startsAtUtc !== null && $this->startsAtUtc >= $this->endsBeforeUtc) {
            throw new DomainException('Report date range start must be before its exclusive end.');
        }
    }

    public function prior(): ?self
    {
        if ($this->startsAtUtc === null) {
            return null;
        }

        $durationSeconds = $this->endsBeforeUtc->getTimestamp() - $this->startsAtUtc->getTimestamp();
        if ($durationSeconds < 1) {
            throw new DomainException('Report date range is too small for prior-period comparison.');
        }

        return new self(
            'prior:'.$this->code,
            $this->startsAtUtc->modify('-'.$durationSeconds.' seconds'),
            $this->startsAtUtc,
        );
    }

    public function databaseStart(): ?string
    {
        return $this->startsAtUtc?->format('Y-m-d H:i:s.u');
    }

    public function databaseEndExclusive(): string
    {
        return $this->endsBeforeUtc->format('Y-m-d H:i:s.u');
    }
}
