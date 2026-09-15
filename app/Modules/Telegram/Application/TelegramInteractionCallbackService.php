<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionSessionStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type CallbackRow object{id:int|string,public_id:string,telegram_interaction_session_id:int|string,telegram_account_id:int|string,session_version:int|string,issue_request_hash:string,issue_command_hash:string,token_hash:string,token_ciphertext:string,action:string,action_payload:string,action_payload_hash:string,state:string,accepted_update_id:int|string|null,accepted_at:string|null,completed_at:string|null,expires_at:string,created_at:string,updated_at:string}
 * @phpstan-type IssueSessionRow object{id:int|string,public_id:string,telegram_account_id:int|string,active_telegram_account_id:int|string|null,user_id:int|string,bot_id:int|string,telegram_user_id:int|string,flow:string,state:string,status:string,payload:string,version:int|string,expires_at:string}
 * @phpstan-type AcceptSessionRow object{id:int|string,public_id:string,telegram_account_id:int|string,active_telegram_account_id:int|string|null,user_id:int|string,bot_id:int|string,telegram_user_id:int|string,flow:string,state:string,status:string,payload:string,version:int|string,expires_at:string}
 * @phpstan-type ReceiptSessionRow object{public_id:string,telegram_account_id:int|string,user_id:int|string,flow:string,state:string,payload:string}
 */
final readonly class TelegramInteractionCallbackService
{
    private const TOKEN_BYTES = 24;

    /** @requirement ARCH-003 DAT-003 SEC-002 SEC-003 QUA-004 */
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private RandomGenerator $random,
        private StringEncrypter $encrypter,
        private TelegramInteractionPolicy $policy,
        private TelegramInteractionDatabaseCapability $databaseCapability,
    ) {}

    /** @param array<string, mixed> $payload */
    public function issue(
        string $sessionPublicId,
        int $expectedVersion,
        string $action,
        array $payload,
        string $requestKey,
        ?int $ttlSeconds = null,
    ): TelegramInteractionCallbackReceipt {
        $this->assertPublicId($sessionPublicId);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Telegram callback session version must be positive.');
        }
        $this->assertAction($action);
        $safePayload = new TelegramInteractionPayload($payload);
        $requestHash = $this->requestHash($requestKey);
        $ttl = $this->ttl($ttlSeconds);
        $commandHash = $this->commandHash([
            'issue',
            $sessionPublicId,
            (string) $expectedVersion,
            $action,
            $safePayload->hash(),
            (string) $ttl,
        ]);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $sessionPublicId,
            $expectedVersion,
            $action,
            $safePayload,
            $requestHash,
            $commandHash,
            $ttl,
        ): TelegramInteractionCallbackReceipt {
            /** @var CallbackRow|null $existing */
            $existing = $connection->table('telegram_interaction_callbacks')
                ->where('issue_request_hash', $requestHash)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->issue_command_hash, $commandHash)) {
                    throw new DomainException('Telegram callback request key conflicts with an accepted callback.');
                }

                return $this->receiptFromRow(
                    $connection,
                    $existing,
                    $this->encrypter->decryptString((string) $existing->token_ciphertext),
                    true,
                );
            }

            /** @var IssueSessionRow|null $session */
            $session = $connection->table('telegram_interaction_sessions as sessions')
                ->where('sessions.public_id', $sessionPublicId)
                ->lockForUpdate()
                ->first([
                    'sessions.id',
                    'sessions.public_id',
                    'sessions.telegram_account_id',
                    'sessions.active_telegram_account_id',
                    'sessions.user_id',
                    'sessions.bot_id',
                    'sessions.telegram_user_id',
                    'sessions.flow',
                    'sessions.state',
                    'sessions.status',
                    'sessions.payload',
                    'sessions.version',
                    'sessions.expires_at',
                ]);
            if ($session === null) {
                throw new DomainException('Telegram callback session does not exist.');
            }
            if ((string) $session->status !== TelegramInteractionSessionStatus::Active->value
                || $session->active_telegram_account_id === null
                || (int) $session->version !== $expectedVersion
                || $this->parseTime((string) $session->expires_at) <= $this->clock->now()) {
                throw new DomainException('Telegram callback session authority is stale.');
            }

            $now = $this->clock->now();
            $requestedExpiry = $now->add(new DateInterval('PT'.$ttl.'S'));
            $sessionExpiry = $this->parseTime((string) $session->expires_at);
            $expiresAt = $requestedExpiry < $sessionExpiry ? $requestedExpiry : $sessionExpiry;
            if ($expiresAt <= $now) {
                throw new DomainException('Telegram callback session has expired.');
            }

            $token = $this->token();
            $tokenHash = hash('sha256', $token);
            $publicId = (string) Str::ulid();
            $callbackId = (int) $this->databaseCapability->run(
                $connection,
                'callback_issue_v1',
                (int) $session->telegram_account_id,
                (int) $session->id,
                $expectedVersion,
                $requestHash,
                null,
                $tokenHash,
                null,
                fn (): int => (int) $connection->table('telegram_interaction_callbacks')->insertGetId([
                    'public_id' => $publicId,
                    'telegram_interaction_session_id' => (int) $session->id,
                    'telegram_account_id' => (int) $session->telegram_account_id,
                    'session_version' => $expectedVersion,
                    'issue_request_hash' => $requestHash,
                    'issue_command_hash' => $commandHash,
                    'token_hash' => $tokenHash,
                    'token_ciphertext' => $this->encrypter->encryptString($token),
                    'action' => $action,
                    'action_payload' => $safePayload->json(),
                    'action_payload_hash' => $safePayload->hash(),
                    'state' => 'pending',
                    'accepted_update_id' => null,
                    'accepted_at' => null,
                    'completed_at' => null,
                    'expires_at' => $this->format($expiresAt),
                    'created_at' => $this->format($now),
                    'updated_at' => $this->format($now),
                ]),
            );

            return new TelegramInteractionCallbackReceipt(
                $publicId,
                $token,
                'telegram-callback:'.$publicId,
                (string) $session->public_id,
                (int) $session->telegram_account_id,
                (int) $session->user_id,
                (string) $session->flow,
                (string) $session->state,
                $expectedVersion,
                $this->payloadFromJson((string) $session->payload),
                $action,
                $safePayload->values(),
                $expiresAt,
                false,
                null,
                false,
                false,
            );
        }, 3);
    }

    public function accept(
        string $botId,
        int $telegramUserId,
        string $token,
        int $updateId,
    ): TelegramInteractionCallbackReceipt {
        $botNumericId = $this->botNumericId($botId);
        if ($telegramUserId < 1 || $updateId < 1) {
            throw new TelegramInteractionRejected('Telegram callback actor or update identifier is invalid.');
        }
        if (preg_match('/\Ai_[A-Za-z0-9_-]{32}\z/', $token) !== 1) {
            throw new TelegramInteractionRejected('Telegram callback token is invalid.');
        }
        $tokenHash = hash('sha256', $token);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $botNumericId,
            $telegramUserId,
            $token,
            $tokenHash,
            $updateId,
        ): TelegramInteractionCallbackReceipt {
            /** @var CallbackRow|null $callback */
            $callback = $connection->table('telegram_interaction_callbacks')
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            if ($callback === null) {
                throw new TelegramInteractionRejected('Telegram callback token is unknown.');
            }

            /** @var AcceptSessionRow|null $session */
            $session = $connection->table('telegram_interaction_sessions as sessions')
                ->where('sessions.id', (int) $callback->telegram_interaction_session_id)
                ->lockForUpdate()
                ->first([
                    'sessions.id',
                    'sessions.public_id',
                    'sessions.telegram_account_id',
                    'sessions.active_telegram_account_id',
                    'sessions.user_id',
                    'sessions.bot_id',
                    'sessions.telegram_user_id',
                    'sessions.flow',
                    'sessions.state',
                    'sessions.status',
                    'sessions.payload',
                    'sessions.version',
                    'sessions.expires_at',
                ]);
            if ($session === null
                || (int) $callback->telegram_account_id !== (int) $session->telegram_account_id
                || (int) $session->bot_id !== $botNumericId
                || (int) $session->telegram_user_id !== $telegramUserId) {
                throw new TelegramInteractionRejected('Telegram callback actor binding is invalid.');
            }

            $state = (string) $callback->state;
            if ($state === 'completed') {
                return $this->receiptFromRows($connection, $callback, $session, $token, true);
            }
            if ($state === 'accepted') {
                return $this->receiptFromRows($connection, $callback, $session, $token, true);
            }
            if ($state !== 'pending') {
                throw new TelegramInteractionRejected('Telegram callback state is invalid.');
            }

            $now = $this->clock->now();
            if ((string) $session->status !== TelegramInteractionSessionStatus::Active->value
                || $session->active_telegram_account_id === null
                || (int) $session->version !== (int) $callback->session_version
                || $this->parseTime((string) $session->expires_at) <= $now
                || $this->parseTime((string) $callback->expires_at) <= $now) {
                throw new TelegramInteractionRejected('Telegram callback authority is stale or expired.');
            }

            $updated = $this->databaseCapability->run(
                $connection,
                'callback_accept_v1',
                (int) $session->telegram_account_id,
                (int) $session->id,
                (int) $callback->session_version,
                (string) $callback->issue_request_hash,
                (int) $callback->id,
                $tokenHash,
                $updateId,
                fn (): int => $connection->table('telegram_interaction_callbacks')
                    ->where('id', $callback->id)
                    ->where('state', 'pending')
                    ->update([
                        'state' => 'accepted',
                        'accepted_update_id' => $updateId,
                        'accepted_at' => $this->format($now),
                        'updated_at' => $this->format($now),
                    ]),
            );
            if ($updated !== 1) {
                throw new RuntimeException('Telegram callback acceptance lost its state fence.');
            }

            /** @var CallbackRow|null $acceptedCallback */
            $acceptedCallback = $connection->table('telegram_interaction_callbacks')
                ->where('id', $callback->id)
                ->first();
            if ($acceptedCallback === null) {
                throw new RuntimeException('Telegram callback acceptance persistence is unavailable.');
            }

            return $this->receiptFromRows($connection, $acceptedCallback, $session, $token, false);
        }, 3);
    }

    public function complete(string $callbackPublicId): void
    {
        $this->assertPublicId($callbackPublicId);
        $connection = $this->database->connection();

        $connection->transaction(function () use ($connection, $callbackPublicId): void {
            /** @var CallbackRow|null $callback */
            $callback = $connection->table('telegram_interaction_callbacks')
                ->where('public_id', $callbackPublicId)
                ->lockForUpdate()
                ->first();
            if ($callback === null) {
                throw new DomainException('Telegram callback does not exist.');
            }
            if ((string) $callback->state === 'completed') {
                return;
            }
            if ((string) $callback->state !== 'accepted') {
                throw new DomainException('Telegram callback must be accepted before completion.');
            }

            $now = $this->clock->now();
            $updated = $this->databaseCapability->run(
                $connection,
                'callback_complete_v1',
                (int) $callback->telegram_account_id,
                (int) $callback->telegram_interaction_session_id,
                (int) $callback->session_version,
                (string) $callback->issue_request_hash,
                (int) $callback->id,
                (string) $callback->token_hash,
                is_numeric($callback->accepted_update_id) ? (int) $callback->accepted_update_id : null,
                fn (): int => $connection->table('telegram_interaction_callbacks')
                    ->where('id', $callback->id)
                    ->where('state', 'accepted')
                    ->update([
                        'state' => 'completed',
                        'completed_at' => $this->format($now),
                        'updated_at' => $this->format($now),
                    ]),
            );
            if ($updated !== 1) {
                throw new RuntimeException('Telegram callback completion lost its state fence.');
            }
        }, 3);
    }

    /** @param CallbackRow $callback */
    private function receiptFromRow(
        Connection $connection,
        object $callback,
        string $token,
        bool $replayed,
    ): TelegramInteractionCallbackReceipt {
        /** @var ReceiptSessionRow|null $session */
        $session = $connection->table('telegram_interaction_sessions as sessions')
            ->where('sessions.id', (int) $callback->telegram_interaction_session_id)
            ->first([
                'sessions.public_id',
                'sessions.telegram_account_id',
                'sessions.user_id',
                'sessions.flow',
                'sessions.state',
                'sessions.payload',
            ]);
        if ($session === null) {
            throw new RuntimeException('Telegram callback session persistence is invalid.');
        }

        return $this->receiptFromRows($connection, $callback, $session, $token, $replayed);
    }

    /**
     * @param  CallbackRow  $callback
     * @param  ReceiptSessionRow  $session
     */
    private function receiptFromRows(
        Connection $connection,
        object $callback,
        object $session,
        string $token,
        bool $replayed,
    ): TelegramInteractionCallbackReceipt {
        $state = (string) $callback->state;
        $snapshot = $connection->table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', (int) $callback->telegram_interaction_session_id)
            ->where('to_version', (int) $callback->session_version)
            ->first(['to_state', 'to_payload']);
        if ($snapshot === null) {
            throw new RuntimeException('Telegram callback interaction snapshot is unavailable.');
        }

        return new TelegramInteractionCallbackReceipt(
            (string) $callback->public_id,
            $token,
            'telegram-callback:'.(string) $callback->public_id,
            (string) $session->public_id,
            (int) $session->telegram_account_id,
            (int) $session->user_id,
            (string) $session->flow,
            (string) $snapshot->to_state,
            (int) $callback->session_version,
            $this->payloadFromJson((string) $snapshot->to_payload),
            (string) $callback->action,
            $this->payloadFromJson((string) $callback->action_payload),
            $this->parseTime((string) $callback->expires_at),
            in_array($state, ['accepted', 'completed'], true),
            is_numeric($callback->accepted_update_id) ? (int) $callback->accepted_update_id : null,
            $state === 'completed',
            $replayed,
            $callback->accepted_at === null ? null : $this->parseTime((string) $callback->accepted_at),
        );
    }

    private function token(): string
    {
        $encoded = rtrim(strtr(base64_encode($this->random->bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        if (strlen($encoded) !== 32) {
            throw new RuntimeException('Telegram callback token encoding has an invalid length.');
        }

        return 'i_'.$encoded;
    }

    private function botNumericId(string $botId): int
    {
        if (preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1 || filter_var($botId, FILTER_VALIDATE_INT) === false) {
            throw new TelegramInteractionRejected('Telegram callback bot identifier is invalid.');
        }

        return (int) $botId;
    }

    private function ttl(?int $ttlSeconds): int
    {
        $ttl = $ttlSeconds ?? $this->policy->callbackTtlSeconds;
        if ($ttl < 30 || $ttl > $this->policy->sessionTtlSeconds) {
            throw new InvalidArgumentException('Telegram callback TTL is invalid.');
        }

        return $ttl;
    }

    private function requestHash(string $requestKey): string
    {
        $requestKey = trim($requestKey);
        if ($requestKey === '' || strlen($requestKey) > 191) {
            throw new InvalidArgumentException('Telegram callback request key is invalid.');
        }

        return hash('sha256', $requestKey);
    }

    /** @param list<string> $parts */
    private function commandHash(array $parts): string
    {
        return hash('sha256', implode("\0", $parts));
    }

    private function assertAction(string $action): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $action) !== 1) {
            throw new InvalidArgumentException('Telegram callback action is invalid.');
        }
    }

    private function assertPublicId(string $publicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new InvalidArgumentException('Telegram interaction public identifier is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private function payloadFromJson(string $payload): array
    {
        $trimmed = ltrim($payload);
        if (! str_starts_with($trimmed, '{')) {
            throw new RuntimeException('Telegram callback payload persistence is invalid.');
        }

        $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Telegram callback payload persistence is invalid.');
        }

        return $decoded;
    }

    private function parseTime(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }
}
