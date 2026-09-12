<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type BindingRow object{id:int|string,bot_id:string,update_id:int|string,telegram_account_id:int|string,user_id:int|string,telegram_user_id:int|string,kind:string,request_hash:string,telegram_interaction_session_id:int|string|null,session_version:int|string|null,created_at:string}
 * @phpstan-type BindingSessionRow object{public_id:string,telegram_account_id:int|string,user_id:int|string,telegram_user_id:int|string,flow:string}
 * @phpstan-type BindingTransitionRow object{to_state:string,to_payload:string}
 */
final readonly class TelegramInteractionUpdateBindingService
{
    /** @requirement ARCH-003 DAT-003 SEC-002 SEC-003 QUA-004 */
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private TelegramInteractionDatabaseCapability $databaseCapability,
    ) {}

    public function existing(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        string $kind,
        string $requestKey,
    ): ?TelegramInteractionUpdateBindingReceipt {
        $botNumericId = $this->botNumericId($botId);
        if ($updateId < 1 || $telegramAccountId < 1) {
            throw new InvalidArgumentException('Telegram interaction update binding identity is invalid.');
        }
        if (! in_array($kind, ['message', 'back', 'cancel'], true)) {
            throw new InvalidArgumentException('Telegram interaction update binding kind is invalid.');
        }
        $requestHash = $this->requestHash($requestKey);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $botId,
            $botNumericId,
            $updateId,
            $telegramAccountId,
            $kind,
            $requestKey,
            $requestHash,
        ): ?TelegramInteractionUpdateBindingReceipt {
            $account = $connection->table('telegram_accounts')
                ->where('id', $telegramAccountId)
                ->lockForUpdate()
                ->first(['id', 'user_id', 'bot_id', 'telegram_user_id']);
            if ($account === null
                || (int) $account->bot_id !== $botNumericId
                || (string) (int) $account->bot_id !== $botId) {
                throw new RuntimeException('Telegram interaction update binding account is invalid.');
            }

            /** @var BindingRow|null $existing */
            $existing = $connection->table('telegram_interaction_update_bindings')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                return null;
            }
            if ((int) $existing->telegram_account_id !== $telegramAccountId
                || (string) $existing->kind !== $kind
                || ! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw new RuntimeException('Telegram interaction update binding conflicts with durable update semantics.');
            }

            return $this->receiptFromRow($connection, $existing, $requestKey, true);
        }, 3);
    }

    public function bind(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        string $kind,
        string $requestKey,
    ): TelegramInteractionUpdateBindingReceipt {
        $botNumericId = $this->botNumericId($botId);
        if ($updateId < 1 || $telegramAccountId < 1) {
            throw new InvalidArgumentException('Telegram interaction update binding identity is invalid.');
        }
        if (! in_array($kind, ['message', 'back', 'cancel'], true)) {
            throw new InvalidArgumentException('Telegram interaction update binding kind is invalid.');
        }
        $requestHash = $this->requestHash($requestKey);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $botId,
            $botNumericId,
            $updateId,
            $telegramAccountId,
            $kind,
            $requestKey,
            $requestHash,
        ): TelegramInteractionUpdateBindingReceipt {
            $account = $connection->table('telegram_accounts')
                ->where('id', $telegramAccountId)
                ->lockForUpdate()
                ->first(['id', 'user_id', 'bot_id', 'telegram_user_id']);
            if ($account === null
                || (int) $account->bot_id !== $botNumericId
                || (string) (int) $account->bot_id !== $botId) {
                throw new RuntimeException('Telegram interaction update binding account is invalid.');
            }

            /** @var BindingRow|null $existing */
            $existing = $connection->table('telegram_interaction_update_bindings')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((int) $existing->telegram_account_id !== $telegramAccountId
                    || (string) $existing->kind !== $kind
                    || ! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new RuntimeException('Telegram interaction update binding conflicts with durable update semantics.');
                }

                return $this->receiptFromRow($connection, $existing, $requestKey, true);
            }

            $session = $connection->table('telegram_interaction_sessions')
                ->where('active_telegram_account_id', $telegramAccountId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first([
                    'id',
                    'telegram_account_id',
                    'user_id',
                    'bot_id',
                    'telegram_user_id',
                    'version',
                    'expires_at',
                ]);
            if ($session !== null) {
                $expiresAt = new \DateTimeImmutable((string) $session->expires_at, new \DateTimeZone('UTC'));
                if ($expiresAt <= $this->clock->now()) {
                    $session = null;
                }
            }

            $sessionId = $session === null ? null : (int) $session->id;
            $sessionVersion = $session === null ? null : (int) $session->version;
            $bindingId = (int) $this->databaseCapability->run(
                $connection,
                'update_bind_v1',
                $telegramAccountId,
                $sessionId,
                $sessionVersion,
                $requestHash,
                null,
                null,
                $updateId,
                fn (): int => (int) $connection->table('telegram_interaction_update_bindings')->insertGetId([
                    'bot_id' => $botId,
                    'update_id' => $updateId,
                    'telegram_account_id' => $telegramAccountId,
                    'user_id' => (int) $account->user_id,
                    'telegram_user_id' => (int) $account->telegram_user_id,
                    'kind' => $kind,
                    'request_hash' => $requestHash,
                    'telegram_interaction_session_id' => $sessionId,
                    'session_version' => $sessionVersion,
                    'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
                ]),
            );

            /** @var BindingRow|null $binding */
            $binding = $connection->table('telegram_interaction_update_bindings')
                ->where('id', $bindingId)
                ->first();
            if ($binding === null) {
                throw new RuntimeException('Telegram interaction update binding persistence is unavailable.');
            }

            return $this->receiptFromRow($connection, $binding, $requestKey, false);
        }, 3);
    }

    /** @param BindingRow $binding */
    private function receiptFromRow(
        Connection $connection,
        object $binding,
        string $requestKey,
        bool $replayed,
    ): TelegramInteractionUpdateBindingReceipt {
        if ($binding->telegram_interaction_session_id === null) {
            return new TelegramInteractionUpdateBindingReceipt(
                (string) $binding->kind,
                $requestKey,
                (int) $binding->telegram_account_id,
                (int) $binding->user_id,
                (int) $binding->telegram_user_id,
                null,
                null,
                null,
                null,
                [],
                $replayed,
                $this->bindingAcceptedAt((string) $binding->created_at),
            );
        }
        if (! is_numeric($binding->session_version)) {
            throw new RuntimeException('Telegram interaction update binding version is invalid.');
        }

        /** @var BindingSessionRow|null $session */
        $session = $connection->table('telegram_interaction_sessions')
            ->where('id', (int) $binding->telegram_interaction_session_id)
            ->first(['public_id', 'telegram_account_id', 'user_id', 'telegram_user_id', 'flow']);
        if ($session === null
            || (int) $session->telegram_account_id !== (int) $binding->telegram_account_id
            || (int) $session->user_id !== (int) $binding->user_id
            || (int) $session->telegram_user_id !== (int) $binding->telegram_user_id) {
            throw new RuntimeException('Telegram interaction update binding session identity is invalid.');
        }

        /** @var BindingTransitionRow|null $snapshot */
        $snapshot = $connection->table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $binding->telegram_interaction_session_id)
            ->where('to_version', (int) $binding->session_version)
            ->first(['to_state', 'to_payload']);
        if ($snapshot === null) {
            throw new RuntimeException('Telegram interaction update binding snapshot is unavailable.');
        }

        return new TelegramInteractionUpdateBindingReceipt(
            (string) $binding->kind,
            $requestKey,
            (int) $binding->telegram_account_id,
            (int) $binding->user_id,
            (int) $binding->telegram_user_id,
            (string) $session->public_id,
            (string) $session->flow,
            (string) $snapshot->to_state,
            (int) $binding->session_version,
            $this->payloadFromJson((string) $snapshot->to_payload),
            $replayed,
            $this->bindingAcceptedAt((string) $binding->created_at),
        );
    }

    private function bindingAcceptedAt(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException('Telegram interaction update binding acceptance timestamp is invalid.');
        }

        return $parsed;
    }

    /** @return array<string, mixed> */
    private function payloadFromJson(string $payload): array
    {
        $trimmed = ltrim($payload);
        if (! str_starts_with($trimmed, '{')) {
            throw new RuntimeException('Telegram interaction update binding payload persistence is invalid.');
        }

        $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Telegram interaction update binding payload persistence is invalid.');
        }

        return $decoded;
    }

    private function requestHash(string $requestKey): string
    {
        $requestKey = trim($requestKey);
        if ($requestKey === '' || strlen($requestKey) > 191) {
            throw new InvalidArgumentException('Telegram interaction update binding request key is invalid.');
        }

        return hash('sha256', $requestKey);
    }

    private function botNumericId(string $botId): int
    {
        if (preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1 || filter_var($botId, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Telegram interaction update binding bot identifier is invalid.');
        }

        return (int) $botId;
    }
}
