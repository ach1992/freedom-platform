<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class TelegramDeliveryDatabaseCapability
{
    private const CONTEXT = 'telegram-delivery-database-authority-v1';

    public function value(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Telegram delivery database capability key is unavailable.');
        }

        return hash_hmac('sha256', self::CONTEXT, $key);
    }

    public function expectedHash(): string
    {
        return hash('sha256', $this->value());
    }

    public function acquireRuntimeLifecycleFence(Connection $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram delivery runtime lifecycle fence requires a database transaction.');
        }

        $this->lockActiveCapability($connection);
    }

    public function assertRuntimeAuthorityReady(Connection $connection): void
    {
        $this->assertReady($connection);
    }

    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    public function runQueue(
        Connection $connection,
        string $authority,
        string $publicId,
        string $requestHash,
        string $fingerprint,
        string $correlationId,
        TelegramMutationRequest $request,
        ?string $durablePresentationText,
        string $botId,
        string $outboxEventId,
        Closure $operation,
    ): mixed {
        if ($request->presentation instanceof ConfidentialTelegramPresentation) {
            TelegramConfidentialPresentationProvenanceGuard::assertQueueSource($connection);
        } else {
            TelegramPresentationProvenanceGuard::assertQueueSource($connection);
        }
        $this->assertReady($connection);
        $armed = false;

        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_authority = ?,
    @app_telegram_delivery_public_id = ?,
    @app_telegram_delivery_request_hash = ?,
    @app_telegram_delivery_fingerprint = ?,
    @app_telegram_delivery_correlation_id = ?,
    @app_telegram_delivery_action = ?,
    @app_telegram_delivery_bot_id = ?,
    @app_telegram_delivery_recipient_chat_id = ?,
    @app_telegram_delivery_target_message_id = ?,
    @app_telegram_delivery_presentation_hash = ?,
    @app_telegram_delivery_outbox_event_id = ?,
    @app_telegram_delivery_effect_authority = NULL,
    @app_telegram_delivery_effect_public_id = NULL,
    @app_telegram_delivery_effect_expected_version = NULL
SQL, [
                $this->value(),
                $authority,
                $publicId,
                $requestHash,
                $fingerprint,
                $correlationId,
                $request->action->value,
                $botId,
                $request->recipientChatId,
                $request->targetMessageId,
                $durablePresentationText === null ? null : hash('sha256', $durablePresentationText),
                $outboxEventId,
            ]);
            $armed = true;

            return $operation();
        } finally {
            $this->clearOrDisconnect($connection, $armed);
        }
    }

    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    public function runEffect(
        Connection $connection,
        string $authority,
        string $publicId,
        int $expectedVersion,
        Closure $operation,
    ): mixed {
        $this->assertReady($connection);
        $armed = false;

        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_authority = NULL,
    @app_telegram_delivery_public_id = NULL,
    @app_telegram_delivery_request_hash = NULL,
    @app_telegram_delivery_fingerprint = NULL,
    @app_telegram_delivery_correlation_id = NULL,
    @app_telegram_delivery_action = NULL,
    @app_telegram_delivery_bot_id = NULL,
    @app_telegram_delivery_recipient_chat_id = NULL,
    @app_telegram_delivery_target_message_id = NULL,
    @app_telegram_delivery_presentation_hash = NULL,
    @app_telegram_delivery_outbox_event_id = NULL,
    @app_telegram_delivery_effect_authority = ?,
    @app_telegram_delivery_effect_public_id = ?,
    @app_telegram_delivery_effect_expected_version = ?
SQL, [
                $this->value(),
                $authority,
                $publicId,
                $expectedVersion,
            ]);
            $armed = true;

            return $operation();
        } finally {
            $this->clearOrDisconnect($connection, $armed);
        }
    }

    private function assertReady(Connection $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram delivery database authority must be armed inside a database transaction.');
        }

        $this->acquireRuntimeLifecycleFence($connection);
        $this->pinAuthorityMetadata($connection);
        try {
            $ready = (new TelegramDeliveryDatabaseAuthoritySurfaceV1)
                ->isReady($connection, $this->expectedHash());
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram delivery database authority is not fully activated.', 0, $exception);
        }

        if (! $ready) {
            throw new RuntimeException('Telegram delivery database authority is not fully activated.');
        }
    }

    private function lockActiveCapability(Connection $connection): void
    {
        try {
            $capability = $connection->selectOne(<<<'SQL'
SELECT id, capability_hash, schema_version, activated_at
FROM telegram_delivery_authority_capability
WHERE id = 1
LOCK IN SHARE MODE
SQL, [], false);
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram delivery database authority is not accepting runtime work.', 0, $exception);
        }

        if ($capability === null
            || (int) ($capability->id ?? 0) !== 1
            || ! is_string($capability->capability_hash ?? null)
            || ! hash_equals($this->expectedHash(), $capability->capability_hash)
            || (int) ($capability->schema_version ?? -1) !== 1
            || ($capability->activated_at ?? null) === null) {
            throw new RuntimeException('Telegram delivery database authority is not accepting runtime work.');
        }
    }

    private function pinAuthorityMetadata(Connection $connection): void
    {
        // One statement opens all three tables on the write session. MariaDB
        // keeps the transaction's metadata locks until commit/rollback, closing
        // the gap between semantic attestation and guarded DML without a cache.
        $connection->selectOne(<<<'SQL'
SELECT
    (SELECT 1 FROM outbox_messages LIMIT 1) AS outbox_pin,
    (SELECT 1 FROM telegram_delivery_authority_capability LIMIT 1) AS capability_pin,
    (SELECT 1 FROM telegram_delivery_operations LIMIT 1) AS operation_pin
SQL, [], false);
    }

    private function clearOrDisconnect(Connection $connection, bool $armed): void
    {
        if (! $armed) {
            $this->disconnect($connection);

            return;
        }

        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_effect_expected_version = NULL,
    @app_telegram_delivery_effect_public_id = NULL,
    @app_telegram_delivery_effect_authority = NULL,
    @app_telegram_delivery_lifecycle_authority = NULL,
    @app_telegram_delivery_outbox_event_id = NULL,
    @app_telegram_delivery_presentation_hash = NULL,
    @app_telegram_delivery_target_message_id = NULL,
    @app_telegram_delivery_recipient_chat_id = NULL,
    @app_telegram_delivery_bot_id = NULL,
    @app_telegram_delivery_action = NULL,
    @app_telegram_delivery_correlation_id = NULL,
    @app_telegram_delivery_fingerprint = NULL,
    @app_telegram_delivery_request_hash = NULL,
    @app_telegram_delivery_public_id = NULL,
    @app_telegram_delivery_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
        } catch (Throwable $exception) {
            $this->disconnect($connection);
            throw $exception;
        }
    }

    private function disconnect(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            $connection->setPdo(null);
            $connection->setReadPdo(null);
            $connection->setDirectPdo(null);
        }
    }
}
