<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Operations\Application\Contracts\TelegramOperationsSnapshotSource;
use App\Modules\Operations\Application\OperationsCenterFact;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class DatabaseTelegramOperationsSnapshotSource implements TelegramOperationsSnapshotSource
{
    private const UTC = 'UTC';

    public function __construct(private DatabaseManager $database) {}

    public function facts(DateTimeImmutable $now): array
    {
        $connection = $this->database->connection();
        $failed = (int) $connection->table('processed_telegram_updates')
            ->whereIn('state', ['failed', 'failed_terminal'])
            ->count();
        $latest = $connection->table('processed_telegram_updates')->max('received_at');
        $observedAt = $this->date($latest);

        return [
            new OperationsCenterFact(
                'webhook',
                'webhook.telegram_last_observed',
                $observedAt === null ? 'unknown' : 'observed',
                $observedAt === null ? 0 : 1,
                null,
                $observedAt,
            ),
            new OperationsCenterFact(
                'webhook',
                'webhook.telegram_failed_updates',
                $failed === 0 ? 'empty' : 'manual_review',
                $failed,
                null,
                $observedAt,
            ),
        ];
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone(self::UTC)))
                ->setTimezone(new DateTimeZone(self::UTC));
        } catch (Throwable) {
            return null;
        }
    }
}
