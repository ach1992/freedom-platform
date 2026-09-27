<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class TelegramRateRetryRetentionLifecycleFence
{
    public const ACTIVE_COMMENT = 'telegram-rate-retry-retention-active-v1';

    public const ROLLBACK_COMMENT = 'telegram-rate-retry-retention-rollback-v1';

    public const TABLE = 'telegram_rate_retry_retention_lifecycle';

    public function acquireRuntimeWriteFence(Connection $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram rate/retry/retention runtime fence requires a database transaction.');
        }

        try {
            // Opening the table inside the writer transaction pins a shared metadata
            // lock until commit/rollback. A rollback cut changes the table comment
            // through ALTER TABLE, so it must drain already-entered writers first.
            $connection->selectOne(
                'SELECT id FROM '.self::TABLE.' LIMIT 1 LOCK IN SHARE MODE',
                [],
                false,
            );
            $table = $connection->table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', self::TABLE)
                ->first(['TABLE_COMMENT']);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Telegram rate/retry/retention foundation is not accepting runtime evidence writes.',
                0,
                $exception,
            );
        }

        if ($table === null
            || ! is_string($table->TABLE_COMMENT ?? null)
            || ! hash_equals(self::ACTIVE_COMMENT, (string) $table->TABLE_COMMENT)
        ) {
            throw new RuntimeException('Telegram rate/retry/retention foundation is not accepting runtime evidence writes.');
        }
    }
}
