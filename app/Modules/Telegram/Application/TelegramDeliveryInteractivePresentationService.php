<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Domain\TelegramInteractionSessionStatus;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type CallbackRow object{id:int|string,public_id:string,telegram_interaction_session_id:int|string,telegram_account_id:int|string,session_version:int|string,token_hash:string,token_ciphertext:string,state:string,expires_at:string}
 * @phpstan-type SessionRow object{id:int|string,public_id:string,telegram_account_id:int|string,active_telegram_account_id:int|string|null,user_id:int|string,bot_id:int|string,telegram_user_id:int|string,status:string,version:int|string,expires_at:string}
 * @phpstan-type SnapshotRow object{delivery_operation_public_id:string,keyboard_snapshot:string,keyboard_snapshot_hash:string,created_at:string}
 */
final readonly class TelegramDeliveryInteractivePresentationService
{
    public function __construct(
        private Clock $clock,
        private StringEncrypter $encrypter,
        private TelegramDeliveryRuntime $runtime,
        private TelegramDeliveryInteractivePresentationDatabaseCapability $databaseCapability,
    ) {}

    public function store(
        Connection $connection,
        string $operationPublicId,
        int $recipientChatId,
        TelegramInlineKeyboardSnapshot $keyboard,
    ): void {
        $this->assertOperationPublicId($operationPublicId);
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram interactive presentation snapshot requires the delivery queue transaction.');
        }
        if ($recipientChatId < 1) {
            throw new DomainException('Telegram callback keyboards are restricted to the bound private actor chat.');
        }

        $this->validatedCallbacks($connection, $keyboard, $recipientChatId, false);
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.u');
        $stored = $this->databaseCapability->runStore(
            $connection,
            $operationPublicId,
            $keyboard->hash(),
            fn (): bool => $connection->table('telegram_delivery_interactive_presentations')->insert([
                'delivery_operation_public_id' => $operationPublicId,
                'keyboard_snapshot' => $keyboard->json(),
                'keyboard_snapshot_hash' => $keyboard->hash(),
                'created_at' => $timestamp,
            ]),
        );
        if ($stored !== true) {
            throw new RuntimeException('Telegram interactive presentation snapshot was not persisted.');
        }
    }

    public function resolve(
        Connection $connection,
        string $operationPublicId,
        int $recipientChatId,
    ): TelegramResolvedInteractivePresentation {
        $this->assertOperationPublicId($operationPublicId);
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram interactive presentation resolution requires the provider-boundary transaction.');
        }
        if ($recipientChatId < 1) {
            throw new DomainException('Telegram callback keyboards are restricted to the bound private actor chat.');
        }
        try {
            $connection->selectOne(
                'SELECT 1 AS surface_pin FROM '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE.' LIMIT 1',
                [],
                false,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram interactive presentation database surface is unavailable.', 0, $exception);
        }
        if (! (new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady($connection)) {
            throw new RuntimeException('Telegram interactive presentation database authority is not ready.');
        }

        /** @var SnapshotRow|null $row */
        $row = $connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->first([
                'delivery_operation_public_id',
                'keyboard_snapshot',
                'keyboard_snapshot_hash',
                'created_at',
            ]);
        if ($row === null) {
            throw new DomainException('Telegram interactive presentation snapshot does not exist.');
        }
        $snapshot = TelegramInlineKeyboardSnapshot::restore((string) $row->keyboard_snapshot);
        $storedHash = (string) $row->keyboard_snapshot_hash;
        if (! hash_equals($snapshot->hash(), $storedHash)) {
            throw new DomainException('Telegram interactive presentation snapshot integrity failed.');
        }

        $callbackData = $this->validatedCallbacks($connection, $snapshot, $recipientChatId, true);

        return new TelegramResolvedInteractivePresentation(
            TelegramResolvedInlineKeyboardMarkup::resolve($snapshot, $callbackData),
            $storedHash,
        );
    }

    /**
     * @return array<string,string>
     */
    private function validatedCallbacks(
        Connection $connection,
        TelegramInlineKeyboardSnapshot $keyboard,
        int $recipientChatId,
        bool $decrypt,
    ): array {
        $callbackPublicIds = $keyboard->callbackPublicIds();
        $lockOrder = $callbackPublicIds;
        sort($lockOrder, SORT_STRING);

        /** @var list<CallbackRow> $callbacks */
        $callbacks = $connection->table('telegram_interaction_callbacks')
            ->whereIn('public_id', $lockOrder)
            ->orderBy('public_id')
            ->lockForUpdate()
            ->get([
                'id',
                'public_id',
                'telegram_interaction_session_id',
                'telegram_account_id',
                'session_version',
                'token_hash',
                'token_ciphertext',
                'state',
                'expires_at',
            ])
            ->all();
        if (count($callbacks) !== count($lockOrder)) {
            throw new DomainException('Telegram inline keyboard callback authority is incomplete.');
        }

        $callbacksByPublicId = [];
        $sessionIds = [];
        foreach ($callbacks as $callback) {
            $publicId = (string) $callback->public_id;
            $callbacksByPublicId[$publicId] = $callback;
            $sessionIds[(int) $callback->telegram_interaction_session_id] = true;
        }
        if (count($sessionIds) !== 1) {
            throw new DomainException('Telegram inline keyboard callbacks must belong to one interaction session.');
        }

        $sessionId = (int) array_key_first($sessionIds);
        /** @var SessionRow|null $session */
        $session = $connection->table('telegram_interaction_sessions')
            ->where('id', $sessionId)
            ->lockForUpdate()
            ->first([
                'id',
                'public_id',
                'telegram_account_id',
                'active_telegram_account_id',
                'user_id',
                'bot_id',
                'telegram_user_id',
                'status',
                'version',
                'expires_at',
            ]);
        if ($session === null) {
            throw new DomainException('Telegram inline keyboard interaction session does not exist.');
        }

        $now = $this->clock->now();
        $botId = $this->botNumericId($this->runtime->botId());
        if ((string) $session->status !== TelegramInteractionSessionStatus::Active->value
            || $session->active_telegram_account_id === null
            || (int) $session->bot_id !== $botId
            || (int) $session->telegram_user_id !== $recipientChatId
            || $this->parseTime((string) $session->expires_at) <= $now) {
            throw new DomainException('Telegram inline keyboard interaction authority is stale.');
        }

        $resolved = [];
        foreach ($callbackPublicIds as $publicId) {
            $callback = $callbacksByPublicId[$publicId] ?? null;
            if ($callback === null
                || (int) $callback->telegram_interaction_session_id !== $sessionId
                || (int) $callback->telegram_account_id !== (int) $session->telegram_account_id
                || (int) $callback->session_version !== (int) $session->version
                || (string) $callback->state !== 'pending'
                || $this->parseTime((string) $callback->expires_at) <= $now) {
                throw new DomainException('Telegram inline keyboard callback authority is stale.');
            }

            if (! $decrypt) {
                continue;
            }

            try {
                $token = $this->encrypter->decryptString((string) $callback->token_ciphertext);
            } catch (Throwable $exception) {
                throw new RuntimeException('Telegram inline keyboard callback material could not be resolved.', 0, $exception);
            }
            if (preg_match('/\Ai_[A-Za-z0-9_-]{32}\z/', $token) !== 1
                || strlen($token) > 64
                || ! hash_equals((string) $callback->token_hash, hash('sha256', $token))) {
                throw new DomainException('Telegram inline keyboard callback material failed integrity validation.');
            }
            $resolved[$publicId] = $token;
        }

        return $resolved;
    }

    private function botNumericId(string $botId): int
    {
        if (preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1
            || filter_var($botId, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('Telegram runtime bot identity is invalid for interactive delivery.');
        }

        return (int) $botId;
    }

    private function parseTime(string $time): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($time, new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram interactive authority timestamp is invalid.', 0, $exception);
        }
    }

    private function assertOperationPublicId(string $operationPublicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $operationPublicId) !== 1) {
            throw new InvalidArgumentException('Telegram delivery operation identity is invalid.');
        }
    }
}
