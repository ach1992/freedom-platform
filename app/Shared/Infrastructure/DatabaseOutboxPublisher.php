<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final readonly class DatabaseOutboxPublisher implements OutboxPublisher
{
    /** @requirement PAY-003 ARCH-004 OPS-003 */
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function publish(
        string $eventId,
        string $eventKey,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        SafeOutboxPayload $payload,
        string $correlationId,
    ): string {
        if (! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('Outbox event ID must be a UUID.');
        }

        if ($eventKey === '' || strlen($eventKey) > 191) {
            throw new InvalidArgumentException('Outbox event key must contain 1-191 characters.');
        }

        $connection = $this->database->connection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Outbox events must be published inside the aggregate transaction.');
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $payloadHash = $payload->hash();

        $inserted = $connection->table('outbox_messages')->insertOrIgnore([
            'id' => $eventId,
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload->json(),
            'payload_hash' => $payloadHash,
            'correlation_id' => $correlationId,
            'available_at' => $now,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return $eventId;
        }

        $existing = $connection->table('outbox_messages')
            ->where('event_key', $eventKey)
            ->first(['id', 'event_type', 'aggregate_type', 'aggregate_id', 'payload_hash']);

        if ($existing === null
            || (string) $existing->event_type !== $eventType
            || (string) $existing->aggregate_type !== $aggregateType
            || (string) $existing->aggregate_id !== $aggregateId
            || ! hash_equals((string) $existing->payload_hash, $payloadHash)
        ) {
            throw new LogicException('Outbox event key was reused with a different payload.');
        }

        return (string) $existing->id;
    }

    public function releaseForDispatch(
        string $eventId,
        string $eventKey,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        SafeOutboxPayload $payload,
        string $correlationId,
    ): void {
        if (! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('Outbox event ID must be a UUID.');
        }

        $connection = $this->database->connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Outbox events must be released inside the aggregate transaction.');
        }

        $released = $connection->table('outbox_messages')
            ->where('id', $eventId)
            ->where('event_key', $eventKey)
            ->where('event_type', $eventType)
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)
            ->where('payload_hash', $payload->hash())
            ->where('correlation_id', $correlationId)
            ->where('dispatch_state', 'authority_pending')
            ->whereNull('processed_at')
            ->whereNull('lease_token')
            ->whereNull('leased_until')
            ->where('attempts', 0)
            ->whereNull('review_reason')
            ->whereNull('last_error_class')
            ->whereNull('last_error_code')
            ->update([
                'dispatch_state' => 'pending',
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
            ]);

        if ($released !== 1) {
            throw new LogicException('Outbox event was not in the expected authority-pending envelope.');
        }
    }
}
