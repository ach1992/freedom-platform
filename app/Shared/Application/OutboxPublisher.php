<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface OutboxPublisher
{
    /**
     * Persist an event in the caller's current database transaction.
     * Payloads must contain identifiers and non-secret event data only.
     *
     */
    public function publish(
        string $eventId,
        string $eventKey,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        SafeOutboxPayload $payload,
        string $correlationId,
    ): string;
}
