<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionSessionStatus;
use App\Shared\Application\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type SessionRow object{id:int|string,public_id:string,telegram_account_id:int|string,active_telegram_account_id:int|string|null,flow:string,state:string,status:string,payload:string,payload_hash:string,version:int|string,expires_at:string,terminal_at:string|null,user_id:int|string}
 * @phpstan-type TransitionReplayRow object{command_hash:string,to_version:int|string,to_state:string,to_status:string,to_payload:string,to_expires_at:string,public_id:string,telegram_account_id:int|string,flow:string,user_id:int|string}
 */
final readonly class TelegramInteractionSessionService
{
    /** @requirement ARCH-003 DAT-003 SEC-002 SEC-003 QUA-004 */
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private TelegramInteractionPolicy $policy,
        private TelegramInteractionDatabaseCapability $databaseCapability,
    ) {}

    /** @param array<string, mixed> $payload */
    public function start(
        int $telegramAccountId,
        string $flow,
        string $state,
        array $payload,
        string $requestKey,
        ?int $ttlSeconds = null,
    ): TelegramInteractionSessionReceipt {
        $this->assertAccountId($telegramAccountId);
        $this->assertName($flow, 'flow');
        $this->assertName($state, 'state');
        $safePayload = new TelegramInteractionPayload($payload);
        $requestHash = $this->requestHash($requestKey);
        $ttl = $this->ttl($ttlSeconds);
        $commandHash = $this->commandHash([
            'start',
            (string) $telegramAccountId,
            $flow,
            $state,
            $safePayload->hash(),
            (string) $ttl,
        ]);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $telegramAccountId,
            $flow,
            $state,
            $safePayload,
            $requestHash,
            $commandHash,
            $ttl,
        ): TelegramInteractionSessionReceipt {
            $replay = $this->replay($connection, $requestHash, $commandHash);
            if ($replay !== null) {
                return $replay;
            }

            $account = $connection->table('telegram_accounts')
                ->where('id', $telegramAccountId)
                ->lockForUpdate()
                ->first(['id', 'user_id']);
            if ($account === null) {
                throw new DomainException('Telegram interaction account does not exist.');
            }

            $this->expireLockedActiveSession($connection, $telegramAccountId);

            $active = $connection->table('telegram_interaction_sessions')
                ->where('active_telegram_account_id', $telegramAccountId)
                ->lockForUpdate()
                ->first(['id']);
            if ($active !== null) {
                throw new DomainException('Telegram interaction account already has an active session.');
            }

            $now = $this->clock->now();
            $expiresAt = $now->add(new DateInterval('PT'.$ttl.'S'));
            $publicId = (string) Str::ulid();
            $sessionId = (int) $this->databaseCapability->run(
                $connection,
                'session_start_v1',
                $telegramAccountId,
                null,
                0,
                $requestHash,
                null,
                null,
                null,
                fn (): int => (int) $connection->table('telegram_interaction_sessions')->insertGetId([
                    'public_id' => $publicId,
                    'telegram_account_id' => $telegramAccountId,
                    'active_telegram_account_id' => $telegramAccountId,
                    'flow' => $flow,
                    'state' => $state,
                    'status' => TelegramInteractionSessionStatus::Active->value,
                    'payload' => $safePayload->json(),
                    'payload_hash' => $safePayload->hash(),
                    'version' => 1,
                    'expires_at' => $this->format($expiresAt),
                    'terminal_at' => null,
                    'created_at' => $this->format($now),
                    'updated_at' => $this->format($now),
                ]),
            );

            $this->recordTransition(
                $connection,
                $sessionId,
                $telegramAccountId,
                $requestHash,
                $commandHash,
                'start',
                0,
                1,
                null,
                $state,
                TelegramInteractionSessionStatus::Active,
                $safePayload,
                $expiresAt,
                $now,
            );

            return new TelegramInteractionSessionReceipt(
                $publicId,
                $telegramAccountId,
                (int) $account->user_id,
                $flow,
                $state,
                TelegramInteractionSessionStatus::Active,
                1,
                $safePayload->values(),
                $expiresAt,
                false,
            );
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function transition(
        string $sessionPublicId,
        int $expectedVersion,
        string $nextState,
        array $payload,
        string $requestKey,
        ?int $ttlSeconds = null,
    ): TelegramInteractionSessionReceipt {
        $this->assertPublicId($sessionPublicId);
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Telegram interaction expected version must be positive.');
        }
        $this->assertName($nextState, 'state');
        $safePayload = new TelegramInteractionPayload($payload);
        $requestHash = $this->requestHash($requestKey);
        $ttl = $this->ttl($ttlSeconds);
        $commandHash = $this->commandHash([
            'transition',
            $sessionPublicId,
            (string) $expectedVersion,
            $nextState,
            $safePayload->hash(),
            (string) $ttl,
        ]);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $sessionPublicId,
            $expectedVersion,
            $nextState,
            $safePayload,
            $requestHash,
            $commandHash,
            $ttl,
        ): TelegramInteractionSessionReceipt {
            $replay = $this->replay($connection, $requestHash, $commandHash);
            if ($replay !== null) {
                return $replay;
            }

            $session = $this->lockSession($connection, $sessionPublicId);
            $this->assertActiveSession($session);
            if ($this->expireLockedSessionIfNeeded($connection, $session)) {
                throw new DomainException('Telegram interaction session has expired.');
            }
            if ((int) $session->version !== $expectedVersion) {
                throw new DomainException('Telegram interaction session version is stale.');
            }

            $now = $this->clock->now();
            $expiresAt = $now->add(new DateInterval('PT'.$ttl.'S'));
            $nextVersion = $expectedVersion + 1;
            $updated = $this->databaseCapability->run(
                $connection,
                'session_transition_v1',
                (int) $session->telegram_account_id,
                (int) $session->id,
                $expectedVersion,
                $requestHash,
                null,
                null,
                null,
                fn (): int => $connection->table('telegram_interaction_sessions')
                    ->where('id', $session->id)
                    ->where('version', $expectedVersion)
                    ->update([
                        'state' => $nextState,
                        'payload' => $safePayload->json(),
                        'payload_hash' => $safePayload->hash(),
                        'version' => $nextVersion,
                        'expires_at' => $this->format($expiresAt),
                        'updated_at' => $this->format($now),
                    ]),
            );
            if ($updated !== 1) {
                throw new RuntimeException('Telegram interaction transition lost its version fence.');
            }

            $this->recordTransition(
                $connection,
                (int) $session->id,
                (int) $session->telegram_account_id,
                $requestHash,
                $commandHash,
                'transition',
                $expectedVersion,
                $nextVersion,
                (string) $session->state,
                $nextState,
                TelegramInteractionSessionStatus::Active,
                $safePayload,
                $expiresAt,
                $now,
            );

            return new TelegramInteractionSessionReceipt(
                (string) $session->public_id,
                (int) $session->telegram_account_id,
                (int) $session->user_id,
                (string) $session->flow,
                $nextState,
                TelegramInteractionSessionStatus::Active,
                $nextVersion,
                $safePayload->values(),
                $expiresAt,
                false,
            );
        }, 3);
    }

    public function cancelActive(int $telegramAccountId, string $requestKey): ?TelegramInteractionSessionReceipt
    {
        $this->assertAccountId($telegramAccountId);
        $requestHash = $this->requestHash($requestKey);
        $commandHash = $this->commandHash(['cancel', (string) $telegramAccountId]);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $telegramAccountId,
            $requestHash,
            $commandHash,
        ): ?TelegramInteractionSessionReceipt {
            $replay = $this->replay($connection, $requestHash, $commandHash);
            if ($replay !== null) {
                return $replay;
            }

            /** @var SessionRow|null $session */
            /** @var SessionRow|null $session */
            $session = $connection->table('telegram_interaction_sessions as sessions')
                ->join('telegram_accounts as accounts', 'accounts.id', '=', 'sessions.telegram_account_id')
                ->where('sessions.active_telegram_account_id', $telegramAccountId)
                ->lockForUpdate()
                ->first($this->sessionColumns());
            if ($session === null) {
                return null;
            }

            if ($this->isExpired($session)) {
                $this->expireLockedSessionIfNeeded($connection, $session);

                return null;
            }

            return $this->terminalize(
                $connection,
                $session,
                TelegramInteractionSessionStatus::Cancelled,
                'cancel',
                $requestHash,
                $commandHash,
            );
        }, 3);
    }

    public function complete(
        string $sessionPublicId,
        int $expectedVersion,
        string $requestKey,
    ): TelegramInteractionSessionReceipt {
        $this->assertPublicId($sessionPublicId);
        $requestHash = $this->requestHash($requestKey);
        $commandHash = $this->commandHash(['complete', $sessionPublicId, (string) $expectedVersion]);
        $connection = $this->database->connection();

        return $connection->transaction(function () use (
            $connection,
            $sessionPublicId,
            $expectedVersion,
            $requestHash,
            $commandHash,
        ): TelegramInteractionSessionReceipt {
            $replay = $this->replay($connection, $requestHash, $commandHash);
            if ($replay !== null) {
                return $replay;
            }

            $session = $this->lockSession($connection, $sessionPublicId);
            $this->assertActiveSession($session);
            if ($this->expireLockedSessionIfNeeded($connection, $session)) {
                throw new DomainException('Telegram interaction session has expired.');
            }
            if ((int) $session->version !== $expectedVersion) {
                throw new DomainException('Telegram interaction session version is stale.');
            }

            return $this->terminalize(
                $connection,
                $session,
                TelegramInteractionSessionStatus::Completed,
                'complete',
                $requestHash,
                $commandHash,
            );
        }, 3);
    }

    public function activeForAccount(int $telegramAccountId): ?TelegramInteractionSessionReceipt
    {
        $this->assertAccountId($telegramAccountId);
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $telegramAccountId): ?TelegramInteractionSessionReceipt {
            /** @var SessionRow|null $session */
            /** @var SessionRow|null $session */
            $session = $connection->table('telegram_interaction_sessions as sessions')
                ->join('telegram_accounts as accounts', 'accounts.id', '=', 'sessions.telegram_account_id')
                ->where('sessions.active_telegram_account_id', $telegramAccountId)
                ->lockForUpdate()
                ->first($this->sessionColumns());
            if ($session === null) {
                return null;
            }

            if ($this->isExpired($session)) {
                $this->expireLockedSessionIfNeeded($connection, $session);

                return null;
            }

            return $this->receiptFromSession($session, false);
        }, 3);
    }

    /** @param SessionRow $session */
    private function terminalize(
        Connection $connection,
        object $session,
        TelegramInteractionSessionStatus $status,
        string $transitionType,
        string $requestHash,
        string $commandHash,
    ): TelegramInteractionSessionReceipt {
        $expectedVersion = (int) $session->version;
        $nextVersion = $expectedVersion + 1;
        $payload = $this->payloadFromJson((string) $session->payload);
        $safePayload = new TelegramInteractionPayload($payload);
        $now = $this->clock->now();
        $authority = match ($status) {
            TelegramInteractionSessionStatus::Cancelled => 'session_cancel_v1',
            TelegramInteractionSessionStatus::Completed => 'session_complete_v1',
            TelegramInteractionSessionStatus::Expired => 'session_expire_v1',
            TelegramInteractionSessionStatus::Active => throw new RuntimeException('Active sessions are not terminal.'),
        };
        $updated = $this->databaseCapability->run(
            $connection,
            $authority,
            (int) $session->telegram_account_id,
            (int) $session->id,
            $expectedVersion,
            $requestHash,
            null,
            null,
            null,
            fn (): int => $connection->table('telegram_interaction_sessions')
                ->where('id', $session->id)
                ->where('version', $expectedVersion)
                ->update([
                    'active_telegram_account_id' => null,
                    'status' => $status->value,
                    'version' => $nextVersion,
                    'terminal_at' => $this->format($now),
                    'updated_at' => $this->format($now),
                ]),
        );
        if ($updated !== 1) {
            throw new RuntimeException('Telegram interaction terminal transition lost its version fence.');
        }

        $this->recordTransition(
            $connection,
            (int) $session->id,
            (int) $session->telegram_account_id,
            $requestHash,
            $commandHash,
            $transitionType,
            $expectedVersion,
            $nextVersion,
            (string) $session->state,
            (string) $session->state,
            $status,
            $safePayload,
            $this->parseTime((string) $session->expires_at),
            $now,
        );

        return new TelegramInteractionSessionReceipt(
            (string) $session->public_id,
            (int) $session->telegram_account_id,
            (int) $session->user_id,
            (string) $session->flow,
            (string) $session->state,
            $status,
            $nextVersion,
            $payload,
            $this->parseTime((string) $session->expires_at),
            false,
        );
    }

    private function expireLockedActiveSession(Connection $connection, int $telegramAccountId): void
    {
        /** @var SessionRow|null $session */
        $session = $connection->table('telegram_interaction_sessions as sessions')
            ->join('telegram_accounts as accounts', 'accounts.id', '=', 'sessions.telegram_account_id')
            ->where('sessions.active_telegram_account_id', $telegramAccountId)
            ->lockForUpdate()
            ->first($this->sessionColumns());
        if ($session !== null) {
            $this->expireLockedSessionIfNeeded($connection, $session);
        }
    }

    /** @param SessionRow $session */
    private function expireLockedSessionIfNeeded(Connection $connection, object $session): bool
    {
        if (! $this->isExpired($session)) {
            return false;
        }

        $requestHash = hash('sha256', implode(':', [
            'telegram-session-expire-v1',
            (string) $session->public_id,
            (string) $session->version,
            (string) $session->expires_at,
        ]));
        $commandHash = $this->commandHash([
            'expire',
            (string) $session->public_id,
            (string) $session->version,
        ]);
        if ($this->replay($connection, $requestHash, $commandHash) === null) {
            $this->terminalize(
                $connection,
                $session,
                TelegramInteractionSessionStatus::Expired,
                'expire',
                $requestHash,
                $commandHash,
            );
        }

        return true;
    }

    /** @param SessionRow $session */
    private function isExpired(object $session): bool
    {
        return $this->parseTime((string) $session->expires_at) <= $this->clock->now();
    }

    /** @return SessionRow */
    private function lockSession(Connection $connection, string $publicId): object
    {
        /** @var SessionRow|null $session */
        $session = $connection->table('telegram_interaction_sessions as sessions')
            ->join('telegram_accounts as accounts', 'accounts.id', '=', 'sessions.telegram_account_id')
            ->where('sessions.public_id', $publicId)
            ->lockForUpdate()
            ->first($this->sessionColumns());
        if ($session === null) {
            throw new DomainException('Telegram interaction session does not exist.');
        }

        return $session;
    }

    /** @param SessionRow $session */
    private function assertActiveSession(object $session): void
    {
        if ((string) $session->status !== TelegramInteractionSessionStatus::Active->value
            || $session->active_telegram_account_id === null) {
            throw new DomainException('Telegram interaction session is not active.');
        }
    }

    private function recordTransition(
        Connection $connection,
        int $sessionId,
        int $telegramAccountId,
        string $requestHash,
        string $commandHash,
        string $transitionType,
        int $fromVersion,
        int $toVersion,
        ?string $fromState,
        string $toState,
        TelegramInteractionSessionStatus $toStatus,
        TelegramInteractionPayload $payload,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $this->databaseCapability->run(
            $connection,
            'session_transition_record_v1',
            $telegramAccountId,
            $sessionId,
            $fromVersion,
            $requestHash,
            null,
            null,
            null,
            fn (): bool => $connection->table('telegram_interaction_transitions')->insert([
                'telegram_interaction_session_id' => $sessionId,
                'request_hash' => $requestHash,
                'command_hash' => $commandHash,
                'transition_type' => $transitionType,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'from_state' => $fromState,
                'to_state' => $toState,
                'to_status' => $toStatus->value,
                'to_payload' => $payload->json(),
                'to_payload_hash' => $payload->hash(),
                'to_expires_at' => $this->format($expiresAt),
                'created_at' => $this->format($createdAt),
            ]),
        );
    }

    private function replay(Connection $connection, string $requestHash, string $commandHash): ?TelegramInteractionSessionReceipt
    {
        /** @var TransitionReplayRow|null $transition */
        $transition = $connection->table('telegram_interaction_transitions as transitions')
            ->join('telegram_interaction_sessions as sessions', 'sessions.id', '=', 'transitions.telegram_interaction_session_id')
            ->join('telegram_accounts as accounts', 'accounts.id', '=', 'sessions.telegram_account_id')
            ->where('transitions.request_hash', $requestHash)
            ->first([
                'transitions.command_hash',
                'transitions.to_version',
                'transitions.to_state',
                'transitions.to_status',
                'transitions.to_payload',
                'transitions.to_expires_at',
                'sessions.public_id',
                'sessions.telegram_account_id',
                'sessions.flow',
                'accounts.user_id',
            ]);
        if ($transition === null) {
            return null;
        }
        if (! hash_equals((string) $transition->command_hash, $commandHash)) {
            throw new DomainException('Telegram interaction request key conflicts with an accepted command.');
        }

        return new TelegramInteractionSessionReceipt(
            (string) $transition->public_id,
            (int) $transition->telegram_account_id,
            (int) $transition->user_id,
            (string) $transition->flow,
            (string) $transition->to_state,
            TelegramInteractionSessionStatus::from((string) $transition->to_status),
            (int) $transition->to_version,
            $this->payloadFromJson((string) $transition->to_payload),
            $this->parseTime((string) $transition->to_expires_at),
            true,
        );
    }

    /** @param SessionRow $session */
    private function receiptFromSession(object $session, bool $replayed): TelegramInteractionSessionReceipt
    {
        return new TelegramInteractionSessionReceipt(
            (string) $session->public_id,
            (int) $session->telegram_account_id,
            (int) $session->user_id,
            (string) $session->flow,
            (string) $session->state,
            TelegramInteractionSessionStatus::from((string) $session->status),
            (int) $session->version,
            $this->payloadFromJson((string) $session->payload),
            $this->parseTime((string) $session->expires_at),
            $replayed,
        );
    }

    /** @return list<string> */
    private function sessionColumns(): array
    {
        return [
            'sessions.id',
            'sessions.public_id',
            'sessions.telegram_account_id',
            'sessions.active_telegram_account_id',
            'sessions.flow',
            'sessions.state',
            'sessions.status',
            'sessions.payload',
            'sessions.payload_hash',
            'sessions.version',
            'sessions.expires_at',
            'sessions.terminal_at',
            'accounts.user_id',
        ];
    }

    private function requestHash(string $requestKey): string
    {
        $requestKey = trim($requestKey);
        if ($requestKey === '' || strlen($requestKey) > 191) {
            throw new InvalidArgumentException('Telegram interaction request key is invalid.');
        }

        return hash('sha256', $requestKey);
    }

    /** @param list<string> $parts */
    private function commandHash(array $parts): string
    {
        return hash('sha256', implode("\0", $parts));
    }

    private function ttl(?int $ttlSeconds): int
    {
        $ttl = $ttlSeconds ?? $this->policy->sessionTtlSeconds;
        if ($ttl < 60 || $ttl > 86_400) {
            throw new InvalidArgumentException('Telegram interaction session TTL is invalid.');
        }

        return $ttl;
    }

    private function assertAccountId(int $telegramAccountId): void
    {
        if ($telegramAccountId < 1) {
            throw new InvalidArgumentException('Telegram interaction account identifier must be positive.');
        }
    }

    private function assertPublicId(string $publicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new InvalidArgumentException('Telegram interaction session identifier is invalid.');
        }
    }

    private function assertName(string $value, string $label): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $value) !== 1) {
            throw new InvalidArgumentException("Telegram interaction {$label} is invalid.");
        }
    }

    /** @return array<string, mixed> */
    private function payloadFromJson(string $payload): array
    {
        $trimmed = ltrim($payload);
        if (! str_starts_with($trimmed, '{')) {
            throw new RuntimeException('Telegram interaction payload persistence is invalid.');
        }

        $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Telegram interaction payload persistence is invalid.');
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
