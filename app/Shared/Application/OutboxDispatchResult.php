<?php

declare(strict_types=1);

namespace App\Shared\Application;

final readonly class OutboxDispatchResult
{
    public function __construct(
        public string $messageId,
        public string $eventKey,
        public int $attempt,
        public OutboxDispatchOutcome $outcome,
    ) {}
}
