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
        string $botId,
        string $outboxEventId,
        Closure $operation,
    ): mixed {
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
                $request->presentation === null ? null : hash('sha256', $request->presentation->text()),
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
