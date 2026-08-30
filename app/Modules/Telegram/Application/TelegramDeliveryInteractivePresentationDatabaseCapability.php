<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class TelegramDeliveryInteractivePresentationDatabaseCapability
{
    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    public function runStore(
        Connection $connection,
        string $operationPublicId,
        string $snapshotHash,
        Closure $operation,
    ): mixed {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram interactive presentation persistence requires a database transaction.');
        }
        $this->pinSurface($connection);
        if (! (new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady($connection)) {
            throw new RuntimeException('Telegram interactive presentation database authority is not ready.');
        }

        $armed = false;
        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_interactive_authority = 'telegram_delivery_interactive_queue_v1',
    @app_telegram_delivery_interactive_public_id = ?,
    @app_telegram_delivery_interactive_snapshot_hash = ?
SQL, [$operationPublicId, $snapshotHash]);
            $armed = true;

            return $operation();
        } catch (Throwable $exception) {
            if (! $armed) {
                $connection->disconnect();
            }
            throw $exception;
        } finally {
            if ($armed) {
                try {
                    $connection->statement(<<<'SQL'
SET @app_telegram_delivery_interactive_snapshot_hash = NULL,
    @app_telegram_delivery_interactive_public_id = NULL,
    @app_telegram_delivery_interactive_authority = NULL
SQL);
                } catch (Throwable $exception) {
                    $connection->disconnect();
                    throw $exception;
                }
            }
        }
    }

    private function pinSurface(Connection $connection): void
    {
        try {
            $connection->selectOne(
                'SELECT 1 AS surface_pin FROM '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE.' LIMIT 1',
                [],
                false,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram interactive presentation database surface is unavailable.', 0, $exception);
        }
    }
}
