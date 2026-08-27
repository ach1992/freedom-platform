<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface OutboxPublisher
{
    /**
     * Persist an event in the caller's current database transaction.
     * Payloads must contain identifiers and non-secret event data only.
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

    /**
     * Release one exact authority-pending event for normal dispatch.
     * The caller must still hold whatever database authority its envelope trigger requires.
     */
    public function releaseForDispatch(
        string $eventId,
        string $eventKey,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        SafeOutboxPayload $payload,
        string $correlationId,
    ): void;
}
