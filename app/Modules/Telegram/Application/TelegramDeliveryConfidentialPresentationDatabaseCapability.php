<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class TelegramDeliveryConfidentialPresentationDatabaseCapability
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
        string $presentationHash,
        string $ciphertextHash,
        string $requestFingerprint,
        Closure $operation,
    ): mixed {
        TelegramConfidentialPresentationProvenanceGuard::assertExactInternalCaller(
            TelegramDeliveryConfidentialPresentationService::class,
            __DIR__.'/TelegramDeliveryConfidentialPresentationService.php',
        );
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram confidential presentation persistence requires a database transaction.');
        }
        $this->pinSurface($connection);
        if (! (new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady($connection)) {
            throw new RuntimeException('Telegram confidential presentation database authority is not ready.');
        }

        $armed = false;
        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_confidential_authority = 'telegram_delivery_confidential_queue_v1',
    @app_telegram_delivery_confidential_public_id = ?,
    @app_telegram_delivery_confidential_presentation_hash = ?,
    @app_telegram_delivery_confidential_ciphertext_hash = ?,
    @app_telegram_delivery_confidential_fingerprint = ?
SQL, [$operationPublicId, $presentationHash, $ciphertextHash, $requestFingerprint]);
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
SET @app_telegram_delivery_confidential_fingerprint = NULL,
    @app_telegram_delivery_confidential_ciphertext_hash = NULL,
    @app_telegram_delivery_confidential_presentation_hash = NULL,
    @app_telegram_delivery_confidential_public_id = NULL,
    @app_telegram_delivery_confidential_authority = NULL
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
                'SELECT 1 AS surface_pin FROM '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE.' LIMIT 1',
                [],
                false,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram confidential presentation database surface is unavailable.', 0, $exception);
        }
    }
}
