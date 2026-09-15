<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class TelegramInteractionDatabaseCapability
{
    private const CONTEXT = 'telegram-interaction-database-authority-v1';

    public function value(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Telegram interaction database capability key is unavailable.');
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
    public function run(
        Connection $connection,
        string $authority,
        int $telegramAccountId,
        ?int $sessionId,
        ?int $expectedVersion,
        string $requestHash,
        ?int $callbackId,
        ?string $callbackHash,
        ?int $updateId,
        Closure $operation,
    ): mixed {
        $armed = false;
        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_interaction_capability = ?,
    @app_telegram_interaction_authority = ?,
    @app_telegram_interaction_account_id = ?,
    @app_telegram_interaction_session_id = ?,
    @app_telegram_interaction_expected_version = ?,
    @app_telegram_interaction_request_hash = ?,
    @app_telegram_interaction_callback_id = ?,
    @app_telegram_interaction_callback_hash = ?,
    @app_telegram_interaction_update_id = ?
SQL, [
                $this->value(),
                $authority,
                $telegramAccountId,
                $sessionId,
                $expectedVersion,
                $requestHash,
                $callbackId,
                $callbackHash,
                $updateId,
            ]);
            $armed = true;

            return $operation();
        } finally {
            if (! $armed) {
                $this->disconnect($connection);
            } else {
                try {
                    $this->clear($connection);
                } catch (Throwable) {
                    $this->disconnect($connection);
                }
            }
        }
    }

    private function clear(Connection $connection): void
    {
        $connection->statement(<<<'SQL'
SET @app_telegram_interaction_update_id = NULL,
    @app_telegram_interaction_callback_hash = NULL,
    @app_telegram_interaction_callback_id = NULL,
    @app_telegram_interaction_request_hash = NULL,
    @app_telegram_interaction_expected_version = NULL,
    @app_telegram_interaction_session_id = NULL,
    @app_telegram_interaction_account_id = NULL,
    @app_telegram_interaction_authority = NULL,
    @app_telegram_interaction_capability = NULL
SQL);
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
