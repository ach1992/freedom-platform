<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
