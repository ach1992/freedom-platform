<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class TelegramRateRetryRetentionLifecycleFence
{
    private const TABLE = 'telegram_rate_retry_retention_lifecycle';

    private const ACTIVE_GATE_COLUMN = 'runtime_write_gate';

    public function acquireRuntimeWriteFence(Connection $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram rate/retry/retention runtime fence requires a database transaction.');
        }

        try {
            $connection->select(
                'SELECT '.self::ACTIVE_GATE_COLUMN.' FROM '.self::TABLE.' LIMIT 1 LOCK IN SHARE MODE',
                [],
                false,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Telegram rate/retry/retention foundation is not accepting runtime evidence writes.',
                0,
                $exception,
            );
        }
    }
}
