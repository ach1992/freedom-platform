<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDeferrer;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class DatabaseOutboxDeferrer implements OutboxDeferrer
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function deferUntil(
        string $messageId,
        string $eventType,
        string $aggregateId,
        string $correlationId,
        DateTimeImmutable $notBefore,
    ): void {
        $connection = $this->database->connection();

        $connection->transaction(function (Connection $connection) use (
            $messageId,
            $eventType,
            $aggregateId,
            $correlationId,
            $notBefore,
        ): void {
            $row = $connection->table('outbox_messages')
                ->where('id', $messageId)
                ->where('event_type', $eventType)
                ->where('aggregate_id', $aggregateId)
                ->where('correlation_id', $correlationId)
                ->whereNull('processed_at')
                ->lockForUpdate()
                ->first(['dispatch_state', 'available_at']);

            if ($row === null
                || ! in_array((string) $row->dispatch_state, ['pending', 'retry', 'leased'], true)
                || ! is_string($row->available_at)
            ) {
                throw new LogicException('Outbox message is not deferrable.');
            }

            $current = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                $row->available_at,
                new DateTimeZone('UTC'),
            );
            if ($current === false) {
                throw new LogicException('Outbox availability timestamp is invalid.');
            }
            if ($current >= $notBefore) {
                return;
            }

            $updated = $connection->table('outbox_messages')
                ->where('id', $messageId)
                ->where('dispatch_state', (string) $row->dispatch_state)
                ->where('available_at', $row->available_at)
                ->whereNull('processed_at')
                ->update([
                    'available_at' => $this->format($notBefore),
                    'updated_at' => $this->format($this->clock->now()),
                ]);

            if ($updated !== 1) {
                throw new LogicException('Outbox deferral lost its exact lifecycle state.');
            }
        }, 3);
    }

    private function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
