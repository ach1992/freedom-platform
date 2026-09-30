<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\OutboxOperationalSnapshot;
use App\Shared\Application\OutboxOperationalSnapshotSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class DatabaseOutboxOperationalSnapshotSource implements OutboxOperationalSnapshotSource
{
    public function __construct(private DatabaseManager $database) {}

    public function snapshot(DateTimeImmutable $now): OutboxOperationalSnapshot
    {
        $connection = $this->database->connection();
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $nowString = $now->format('Y-m-d H:i:s.u');

        $due = (int) DatabaseOutboxClaimQuery::claimable($connection, $nowString)->count();
        $oldest = DatabaseOutboxClaimQuery::claimable($connection, $nowString)->min('available_at');
        $review = (int) DatabaseOutboxClaimQuery::reviewRequired($connection)->count();

        return new OutboxOperationalSnapshot(
            $due,
            $this->date($oldest),
            $review,
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
