<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;

final readonly class DatabaseOutboxContractRetirementGuard
{
    public function __construct(private DatabaseManager $database) {}

    public function assertRetirable(string $eventType, int $contractVersion): void
    {
        if ($eventType === '' || strlen($eventType) > 191 || $contractVersion < 1 || $contractVersion > 65_535) {
            throw new InvalidArgumentException('Outbox contract identity is invalid.');
        }

        $remaining = $this->database->connection()
            ->table('outbox_messages')
            ->where('event_type', $eventType)
            ->where('contract_version', $contractVersion)
            ->whereNull('processed_at')
            ->count();

        if ($remaining !== 0) {
            throw new LogicException('Outbox contract version still has unprocessed durable messages.');
        }
    }
}
