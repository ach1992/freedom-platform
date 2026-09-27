<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;

interface OutboxDeferrer
{
    /**
     * Raise the availability floor for one exact unprocessed Outbox message.
     *
     * The Shared Outbox owner keeps lifecycle mutation behind this boundary so
     * feature modules never update outbox_messages directly.
     */
    public function deferUntil(
        string $messageId,
        string $eventType,
        string $aggregateId,
        string $correlationId,
        DateTimeImmutable $notBefore,
    ): void;
}
