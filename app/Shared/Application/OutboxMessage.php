<?php

declare(strict_types=1);

namespace App\Shared\Application;

use InvalidArgumentException;

final readonly class OutboxMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $eventKey,
        public string $eventType,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public string $correlationId,
        public int $attempt,
        public int $contractVersion,
    ) {
        if ($id === '' || $eventKey === '' || $eventType === '' || $aggregateType === '' || $aggregateId === '') {
            throw new InvalidArgumentException('Outbox message identity fields must not be empty.');
        }

        if ($attempt < 1) {
            throw new InvalidArgumentException('Outbox dispatch attempt must be positive.');
        }

        if ($contractVersion < 1 || $contractVersion > 65_535) {
            throw new InvalidArgumentException('Outbox contract version must be between 1 and 65535.');
        }
    }
}
