<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class TelegramRateRetryRetentionLifecycleFence
{
    public function acquireRuntimeWriteFence(Connection $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram rate/retry/retention runtime fence requires a database transaction.');
        }

        try {
            $row = $connection->selectOne(<<<'SQL'
SELECT id, rollback_started_at
FROM telegram_rate_retry_retention_lifecycle
WHERE id = 1
LOCK IN SHARE MODE
SQL, [], false);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Telegram rate/retry/retention foundation is not accepting runtime evidence writes.',
                0,
                $exception,
            );
        }

        if ($row === null
            || (int) ($row->id ?? 0) !== 1
            || ($row->rollback_started_at ?? null) !== null
        ) {
            throw new RuntimeException('Telegram rate/retry/retention foundation is not accepting runtime evidence writes.');
        }
    }
}
