<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OutboxOperationalSnapshot
{
    public function __construct(
        public int $dueBacklog,
        public ?DateTimeImmutable $oldestDueAtUtc,
        public int $reviewRequired,
    ) {
        if ($dueBacklog < 0 || $reviewRequired < 0) {
            throw new InvalidArgumentException('Outbox operational counts must not be negative.');
        }
    }
}
