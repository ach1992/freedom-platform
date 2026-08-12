<?php

declare(strict_types=1);

namespace App\Shared\Application;

use InvalidArgumentException;

final readonly class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload
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
    ) {
        if ($id === '' || $eventKey === '' || $eventType === '' || $aggregateType === '' || $aggregateId === '') {
            throw new InvalidArgumentException('Outbox message identity fields must not be empty.');
        }

        if ($attempt < 1) {
            throw new InvalidArgumentException('Outbox dispatch attempt must be positive.');
        }
    }
}
