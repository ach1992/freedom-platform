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

    public function valueMatchingHash(string $capabilityHash): ?string
    {
        if (preg_match('/\\A[0-9a-f]{64}\\z/', $capabilityHash) !== 1) {
            return null;
        }

        foreach ($this->configuredValues() as $value) {
            if (hash_equals(hash('sha256', $value), $capabilityHash)) {
                return $value;
            }
        }

        return null;
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
        $capabilityValue = $this->activeValue($connection);
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
                $capabilityValue,
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

    private function activeValue(Connection $connection): string
    {
        try {
            $row = $connection->selectOne(<<<'SQL'
SELECT id, capability_hash
FROM telegram_interaction_authority_capability
WHERE id = 1
SQL, [], false);
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram interaction database authority is not accepting runtime work.', 0, $exception);
        }

        $capabilityHash = $row !== null && is_string($row->capability_hash ?? null)
            ? $row->capability_hash
            : '';
        $value = $this->valueMatchingHash($capabilityHash);
        if ($row === null || (int) ($row->id ?? 0) !== 1 || $value === null) {
            throw new RuntimeException('Telegram interaction database authority is not accepting runtime work.');
        }

        return $value;
    }

    /** @return list<string> */
    private function configuredValues(): array
    {
        $currentKey = config('app.key');
        $previousKeys = config('app.previous_keys', []);
        if (! is_string($currentKey) || $currentKey === '' || ! is_array($previousKeys)) {
            throw new RuntimeException('Telegram interaction database capability keyring is unavailable.');
        }

        $keys = [$currentKey];
        foreach ($previousKeys as $previousKey) {
            if (! is_string($previousKey) || $previousKey === '') {
                throw new RuntimeException('Telegram interaction database capability keyring is unavailable.');
            }
            if (! in_array($previousKey, $keys, true)) {
                $keys[] = $previousKey;
            }
        }

        return array_map(
            static fn (string $key): string => hash_hmac('sha256', self::CONTEXT, $key),
            $keys,
        );
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
