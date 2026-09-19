<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type DirectSourceRow object{
 *     id:int|string,
 *     public_id:string,
 *     create_request_hash:string,
 *     actor_administrator_id:int|string,
 *     bot_id:string,
 *     target_account_public_id:string,
 *     target_telegram_user_id:string,
 *     content_type:string,
 *     content_ciphertext:string,
 *     content_integrity_hash:string,
 *     content_length:int|string,
 *     media_public_id:string|null,
 *     media_detected_mime:string|null,
 *     media_byte_size:int|string|null,
 *     media_content_sha256:string|null,
 *     correlation_id:string,
 *     delivery_operation_public_id:string|null,
 *     expires_at:string,
 *     confirmed_at:string|null,
 *     queued_at:string|null,
 *     created_at:string,
 *     updated_at:string
 * }
 */
final readonly class TelegramAdministratorDirectSourceMessageService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private TelegramAdministratorCustomerTargetDiscovery $targets,
        private StringEncrypter $encrypter,
        private ConfigRepository $config,
        private Clock $clock,
        private TelegramDeliveryQueueService $delivery,
    ) {}

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function createDraft(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        TelegramSourceMessageMode $mode,
        int $sourceChatId,
        int $sourceMessageId,
        string $requestKey,
    ): TelegramAdministratorDirectMessageDraft {
        if ($sourceChatId < 1 || $sourceMessageId < 1) {
            throw new DomainException('Telegram administrator direct source-message identity is invalid.');
        }

        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramAdministratorDirectMessageService::PERMISSION,
        );
        $this->assertSourceBoundToActor($actorUserId, $botId, $sourceChatId);
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $requestHash = $this->requestHash($requestKey);
        $connection = $this->database->connection();

        try {
            return $connection->transaction(function (Connection $connection) use (
                $administratorId,
                $botId,
                $target,
                $mode,
                $sourceChatId,
                $sourceMessageId,
                $requestHash,
            ): TelegramAdministratorDirectMessageDraft {
                $existing = $this->rowByRequestHash($connection, $requestHash, true);
                if ($existing !== null) {
                    return $this->draftFromRow(
                        $existing,
                        $administratorId,
                        $botId,
                        $target,
                        $mode,
                        $sourceChatId,
                        $sourceMessageId,
                        true,
                    );
                }

                $now = $this->clock->now();
                $publicId = strtoupper((string) Str::ulid());
                $correlationId = 'tg-admin-dm:'.substr(hash('sha256', $publicId), 0, 40);
                $payload = $this->sourcePayload($sourceChatId, $sourceMessageId);
                $timestamp = $this->formatTime($now);
                $expiresAt = $this->formatTime($now->modify('+'.$this->draftTtlSeconds().' seconds'));

                $connection->table('telegram_administrator_direct_messages')->insert([
                    'public_id' => $publicId,
                    'create_request_hash' => $requestHash,
                    'actor_administrator_id' => $administratorId,
                    'bot_id' => $botId,
                    'target_account_public_id' => $target->accountPublicId,
                    'target_telegram_user_id' => $target->telegramUserId,
                    'content_type' => $mode->value,
                    'content_ciphertext' => $this->encryptSource($payload),
                    'content_integrity_hash' => $this->integrityHash(
                        $publicId,
                        $administratorId,
                        $botId,
                        $target->accountPublicId,
                        $target->telegramUserId,
                        $mode,
                        $sourceChatId,
                        $sourceMessageId,
                    ),
                    'content_length' => 0,
                    'media_public_id' => null,
                    'media_detected_mime' => null,
                    'media_byte_size' => null,
                    'media_content_sha256' => null,
                    'inline_keyboard_ciphertext' => null,
                    'inline_keyboard_hash' => null,
                    'correlation_id' => $correlationId,
                    'delivery_operation_public_id' => null,
                    'expires_at' => $expiresAt,
                    'confirmed_at' => null,
                    'queued_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $created = $this->rowByRequestHash($connection, $requestHash, true);
                if ($created === null) {
                    throw new RuntimeException('Telegram administrator direct source-message draft was not persisted.');
                }

                return $this->draftFromRow(
                    $created,
                    $administratorId,
                    $botId,
                    $target,
                    $mode,
                    $sourceChatId,
                    $sourceMessageId,
                    false,
                );
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $existing = $this->rowByRequestHash($connection, $requestHash, false);
            if ($existing === null) {
                throw $exception;
            }

            return $this->draftFromRow(
                $existing,
                $administratorId,
                $botId,
                $target,
                $mode,
                $sourceChatId,
                $sourceMessageId,
                true,
            );
        }
    }

    /** @requirement COM-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 */
    public function draftForConfirmation(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramAdministratorDirectMessageDraft {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramAdministratorDirectMessageService::PERMISSION,
        );
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct source-message draft is unavailable.');
        }

        return $this->draftFromRow($row, $administratorId, $botId, $target, null, null, null, false);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function acceptConfirmation(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramAdministratorDirectMessageDraft {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramAdministratorDirectMessageService::PERMISSION,
        );
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $connection = $this->database->connection();

        return $connection->transaction(function (Connection $connection) use (
            $administratorId,
            $botId,
            $target,
            $publicId,
        ): TelegramAdministratorDirectMessageDraft {
            $row = $this->rowByPublicId($connection, $publicId, true);
            if ($row === null) {
                throw new DomainException('Telegram administrator direct source-message draft is unavailable.');
            }

            $draft = $this->draftFromRow($row, $administratorId, $botId, $target, null, null, null, false);
            if ($row->confirmed_at !== null) {
                return $draft;
            }

            $now = $this->clock->now();
            if ($this->parseTime((string) $row->expires_at) <= $now) {
                throw new DomainException('Telegram administrator direct source-message draft has expired.');
            }

            $timestamp = $this->formatTime($now);
            $updated = $connection->table('telegram_administrator_direct_messages')
                ->where('id', (int) $row->id)
                ->whereNull('confirmed_at')
                ->update([
                    'confirmed_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException(
                    'Telegram administrator direct source-message confirmation acceptance was not persisted.',
                );
            }

            return $draft;
        }, 3);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 OPS-003 QUA-001 QUA-004 */
    public function confirm(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): TelegramDeliveryOperationReceipt {
        TelegramSourceMessageDeliveryProvenanceGuard::assertDirectMessageFacadeCaller();

        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct source-message draft is unavailable.');
        }
        if ($row->confirmed_at === null) {
            throw new DomainException(
                'Telegram administrator direct source-message confirmation has not been accepted.',
            );
        }

        $administratorId = $this->assertStoredActor($row, $actorUserId);
        $draft = $this->draftFromStoredRow($row, $administratorId, $botId, null, null, null, false);
        if ($draft->contentType === TelegramSourceMessageMode::Forward->value && $inlineKeyboard !== null) {
            throw new DomainException('Telegram forward direct messages cannot carry authored reply markup.');
        }
        if ($draft->sourceChatId === null || $draft->sourceMessageId === null) {
            throw new RuntimeException('Telegram direct source-message draft identity is incomplete.');
        }

        $recipientChatId = $this->recipientChatId($draft->targetTelegramUserId);
        $reference = TelegramSourceMessagePresentationReference::administratorDirectMessage($publicId);
        $requestKey = 'tg-admin-direct-message-send:'.$publicId;

        $existing = $this->delivery->findExistingSourceMessageReference(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            $reference,
            $requestKey,
            $draft->correlationId,
            $inlineKeyboard,
        );
        if ($existing !== null) {
            $this->linkDeliveryOperation($publicId, $administratorId, $existing->publicId);

            return $existing;
        }

        try {
            $authorizedAdministratorId = $this->administrators->authorizeUser(
                $actorUserId,
                TelegramAdministratorDirectMessageService::PERMISSION,
            );
            if ($authorizedAdministratorId !== $administratorId) {
                throw new RuntimeException(
                    'Telegram administrator direct source-message actor changed during confirmation.',
                );
            }
            $this->assertSourceBoundToActor($actorUserId, $botId, $draft->sourceChatId);
            $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
            $this->assertTargetMatches(
                $target,
                $draft->targetAccountPublicId,
                $draft->targetTelegramUserId,
            );

            // Re-authorize source/target at the immediate new external-effect boundary.
            $recheckedAdministratorId = $this->administrators->authorizeUser(
                $actorUserId,
                TelegramAdministratorDirectMessageService::PERMISSION,
            );
            if ($recheckedAdministratorId !== $administratorId) {
                throw new RuntimeException(
                    'Telegram administrator direct source-message actor changed during confirmation.',
                );
            }
            $this->assertSourceBoundToActor($actorUserId, $botId, $draft->sourceChatId);
            $currentTarget = $this->targets->resolve($actorUserId, $botId, $selectionToken);
            $this->assertTargetMatches(
                $currentTarget,
                $draft->targetAccountPublicId,
                $draft->targetTelegramUserId,
            );
        } catch (Throwable $exception) {
            $existing = $this->delivery->findExistingSourceMessageReference(
                TelegramDeliveryAction::Send,
                $recipientChatId,
                $reference,
                $requestKey,
                $draft->correlationId,
                $inlineKeyboard,
            );
            if ($existing !== null) {
                $this->linkDeliveryOperation($publicId, $administratorId, $existing->publicId);

                return $existing;
            }

            throw $exception;
        }

        $receipt = $this->delivery->queueSourceMessageReference(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            $reference,
            $requestKey,
            $draft->correlationId,
            $inlineKeyboard,
        );
        $this->linkDeliveryOperation($publicId, $administratorId, $receipt->publicId);

        return $receipt;
    }

    /** @requirement COM-001 SEC-002 SEC-003 DAT-002 DAT-003 OPS-003 */
    public function presentationForDelivery(
        string $publicId,
        int $recipientChatId,
    ): TelegramResolvedSourceMessagePresentation {
        TelegramSourceMessageDeliveryProvenanceGuard::assertDirectMessageFacadeCaller();

        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null || $row->confirmed_at === null) {
            throw new DomainException('Telegram administrator direct source-message delivery reference is unavailable.');
        }

        $administratorId = (int) $row->actor_administrator_id;
        $draft = $this->draftFromStoredRow(
            $row,
            $administratorId,
            (string) $row->bot_id,
            null,
            null,
            null,
            false,
        );
        if ($this->recipientChatId($draft->targetTelegramUserId) !== $recipientChatId
            || $draft->sourceChatId === null
            || $draft->sourceMessageId === null) {
            throw new DomainException('Telegram administrator direct source-message delivery target is invalid.');
        }

        $mode = TelegramSourceMessageMode::tryFrom($draft->contentType);
        if ($mode === null) {
            throw new RuntimeException('Telegram administrator direct source-message mode is invalid.');
        }

        $actorUserId = $this->actorUserIdForAdministrator($administratorId);
        $authorizedAdministratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramAdministratorDirectMessageService::PERMISSION,
        );
        if ($authorizedAdministratorId !== $administratorId) {
            throw new RuntimeException(
                'Telegram administrator direct source-message actor changed before provider delivery.',
            );
        }
        $this->assertSourceBoundToActor($actorUserId, (string) $row->bot_id, $draft->sourceChatId);
        $target = $this->targets->resolve(
            $actorUserId,
            (string) $row->bot_id,
            $this->selectionTokenForStoredTarget(
                $actorUserId,
                (string) $row->bot_id,
                $draft->targetAccountPublicId,
            ),
        );
        $this->assertTargetMatches(
            $target,
            $draft->targetAccountPublicId,
            $draft->targetTelegramUserId,
        );

        return new TelegramResolvedSourceMessagePresentation(
            $mode,
            $draft->sourceChatId,
            $draft->sourceMessageId,
        );
    }

    /** @param DirectSourceRow $row */
    private function draftFromRow(
        object $row,
        int $administratorId,
        string $botId,
        TelegramAdministratorCustomerTarget $target,
        ?TelegramSourceMessageMode $expectedMode,
        ?int $expectedSourceChatId,
        ?int $expectedSourceMessageId,
        bool $replayed,
    ): TelegramAdministratorDirectMessageDraft {
        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || ! hash_equals((string) $row->target_account_public_id, $target->accountPublicId)
            || ! hash_equals((string) $row->target_telegram_user_id, $target->telegramUserId)) {
            throw new DomainException(
                'Telegram administrator direct source-message draft does not match the current actor or target.',
            );
        }

        return $this->draftFromStoredRow(
            $row,
            $administratorId,
            $botId,
            $expectedMode,
            $expectedSourceChatId,
            $expectedSourceMessageId,
            $replayed,
        );
    }

    /** @param DirectSourceRow $row */
    private function draftFromStoredRow(
        object $row,
        int $administratorId,
        string $botId,
        ?TelegramSourceMessageMode $expectedMode,
        ?int $expectedSourceChatId,
        ?int $expectedSourceMessageId,
        bool $replayed,
    ): TelegramAdministratorDirectMessageDraft {
        $publicId = (string) $row->public_id;
        $targetAccountPublicId = (string) $row->target_account_public_id;
        $targetTelegramUserId = (string) $row->target_telegram_user_id;
        $mode = TelegramSourceMessageMode::tryFrom((string) $row->content_type);
        $this->assertPublicId($publicId, 'Telegram administrator direct source-message identity');

        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || $mode === null
            || (int) $row->content_length !== 0
            || $row->media_public_id !== null
            || $row->media_detected_mime !== null
            || $row->media_byte_size !== null
            || $row->media_content_sha256 !== null) {
            throw new DomainException(
                'Telegram administrator direct source-message draft does not match the stored actor or bot.',
            );
        }
        if ($row->confirmed_at === null
            && $this->parseTime((string) $row->expires_at) <= $this->clock->now()) {
            throw new DomainException('Telegram administrator direct source-message draft has expired.');
        }
        if ($row->confirmed_at !== null) {
            if (! is_string($row->confirmed_at)) {
                throw new RuntimeException(
                    'Telegram administrator direct source-message confirmation timestamp is invalid.',
                );
            }
            $confirmedAt = $this->parseTime($row->confirmed_at);
            if ($confirmedAt < $this->parseTime((string) $row->created_at)
                || $confirmedAt >= $this->parseTime((string) $row->expires_at)) {
                throw new DomainException(
                    'Telegram administrator direct source-message confirmation evidence is invalid.',
                );
            }
        }

        [$sourceChatId, $sourceMessageId] = $this->sourceFromPayload(
            $this->decryptSource((string) $row->content_ciphertext),
        );
        if (! $this->integrityHashMatches(
            (string) $row->content_integrity_hash,
            $publicId,
            $administratorId,
            $botId,
            $targetAccountPublicId,
            $targetTelegramUserId,
            $mode,
            $sourceChatId,
            $sourceMessageId,
        )) {
            throw new DomainException(
                'Telegram administrator direct source-message content integrity validation failed.',
            );
        }

        if ($expectedMode !== null && $expectedMode !== $mode) {
            throw new RuntimeException(
                'Telegram administrator direct source-message idempotency key conflicts with delivery mode.',
            );
        }
        if ($expectedSourceChatId !== null && $expectedSourceChatId !== $sourceChatId) {
            throw new RuntimeException(
                'Telegram administrator direct source-message idempotency key conflicts with source chat.',
            );
        }
        if ($expectedSourceMessageId !== null && $expectedSourceMessageId !== $sourceMessageId) {
            throw new RuntimeException(
                'Telegram administrator direct source-message idempotency key conflicts with source message.',
            );
        }

        return new TelegramAdministratorDirectMessageDraft(
            $publicId,
            $targetAccountPublicId,
            $targetTelegramUserId,
            '',
            (string) $row->correlation_id,
            $replayed,
            $mode->value,
            null,
            null,
            null,
            null,
            $sourceChatId,
            $sourceMessageId,
        );
    }

    private function actorUserIdForAdministrator(int $administratorId): int
    {
        $actorUserId = $this->database->connection()
            ->table('administrators')
            ->where('id', $administratorId)
            ->value('user_id');
        if ((! is_int($actorUserId) && ! is_string($actorUserId))
            || filter_var($actorUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException(
                'Telegram administrator direct source-message actor is unavailable for provider delivery.',
            );
        }

        return (int) $actorUserId;
    }

    private function selectionTokenForStoredTarget(
        int $actorUserId,
        string $botId,
        string $targetAccountPublicId,
    ): string {
        return substr(
            hash(
                'sha256',
                "telegram-admin-customer-target-v1:{$actorUserId}:{$botId}:{$targetAccountPublicId}",
            ),
            0,
            40,
        );
    }

    private function assertSourceBoundToActor(int $actorUserId, string $botId, int $sourceChatId): void
    {
        if ($actorUserId < 1 || $sourceChatId < 1) {
            throw new DomainException('Telegram administrator direct source-message actor binding is invalid.');
        }

        $storedTelegramUserId = $this->database->connection()
            ->table('telegram_accounts')
            ->where('bot_id', $botId)
            ->where('user_id', $actorUserId)
            ->where('is_bot', false)
            ->value('telegram_user_id');
        if ((! is_int($storedTelegramUserId) && ! is_string($storedTelegramUserId))
            || (string) $storedTelegramUserId !== (string) $sourceChatId) {
            throw new DomainException(
                'Telegram administrator direct source-message must originate from the bound administrator private chat.',
            );
        }
    }

    private function sourcePayload(int $sourceChatId, int $sourceMessageId): string
    {
        $encoded = json_encode(
            [
                'source_chat_id' => $sourceChatId,
                'source_message_id' => $sourceMessageId,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        if (strlen($encoded) > 256) {
            throw new RuntimeException('Telegram administrator direct source-message payload is too large.');
        }

        return $encoded;
    }

    /** @return array{0:int,1:int} */
    private function sourceFromPayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException(
                'Telegram administrator direct source-message content integrity validation failed.',
            );
        }
        if (! is_array($decoded)
            || array_is_list($decoded)
            || array_keys($decoded) !== ['source_chat_id', 'source_message_id']
            || ! is_int($decoded['source_chat_id'])
            || $decoded['source_chat_id'] < 1
            || ! is_int($decoded['source_message_id'])
            || $decoded['source_message_id'] < 1) {
            throw new DomainException(
                'Telegram administrator direct source-message content integrity validation failed.',
            );
        }

        return [$decoded['source_chat_id'], $decoded['source_message_id']];
    }

    private function encryptSource(string $payload): string
    {
        try {
            $ciphertext = $this->encrypter->encryptString($payload);
        } catch (Throwable) {
            throw new RuntimeException(
                'Telegram administrator direct source-message identity could not be encrypted.',
            );
        }
        if ($ciphertext === '' || strlen($ciphertext) > 65_536 || hash_equals($ciphertext, $payload)) {
            throw new RuntimeException(
                'Telegram administrator direct source-message ciphertext is invalid.',
            );
        }

        return $ciphertext;
    }

    private function decryptSource(string $ciphertext): string
    {
        try {
            $payload = $this->encrypter->decryptString($ciphertext);
        } catch (Throwable) {
            throw new DomainException(
                'Telegram administrator direct source-message content integrity validation failed.',
            );
        }
        if ($payload === '' || strlen($payload) > 256 || str_contains($payload, "\0")) {
            throw new DomainException(
                'Telegram administrator direct source-message content integrity validation failed.',
            );
        }

        return $payload;
    }

    private function integrityHash(
        string $publicId,
        int $administratorId,
        string $botId,
        string $targetAccountPublicId,
        string $targetTelegramUserId,
        TelegramSourceMessageMode $mode,
        int $sourceChatId,
        int $sourceMessageId,
    ): string {
        return $this->integrityHashWithKey(
            $publicId,
            $administratorId,
            $botId,
            $targetAccountPublicId,
            $targetTelegramUserId,
            $mode,
            $sourceChatId,
            $sourceMessageId,
            $this->integrityKeys()[0],
        );
    }

    private function integrityHashMatches(
        string $storedHash,
        string $publicId,
        int $administratorId,
        string $botId,
        string $targetAccountPublicId,
        string $targetTelegramUserId,
        TelegramSourceMessageMode $mode,
        int $sourceChatId,
        int $sourceMessageId,
    ): bool {
        if (preg_match('/\A[0-9a-f]{64}\z/', $storedHash) !== 1) {
            return false;
        }

        foreach ($this->integrityKeys() as $key) {
            if (hash_equals(
                $storedHash,
                $this->integrityHashWithKey(
                    $publicId,
                    $administratorId,
                    $botId,
                    $targetAccountPublicId,
                    $targetTelegramUserId,
                    $mode,
                    $sourceChatId,
                    $sourceMessageId,
                    $key,
                ),
            )) {
                return true;
            }
        }

        return false;
    }

    private function integrityHashWithKey(
        string $publicId,
        int $administratorId,
        string $botId,
        string $targetAccountPublicId,
        string $targetTelegramUserId,
        TelegramSourceMessageMode $mode,
        int $sourceChatId,
        int $sourceMessageId,
        string $key,
    ): string {
        return hash_hmac(
            'sha256',
            implode("\0", [
                'telegram-admin-direct-source-message-v1',
                $publicId,
                (string) $administratorId,
                $botId,
                $targetAccountPublicId,
                $targetTelegramUserId,
                $mode->value,
                (string) $sourceChatId,
                (string) $sourceMessageId,
            ]),
            $key,
        );
    }

    /** @return non-empty-list<string> */
    private function integrityKeys(): array
    {
        $currentKey = $this->config->get('app.key');
        $previousKeys = $this->config->get('app.previous_keys', []);
        if (! is_string($currentKey) || strlen($currentKey) < 16 || ! is_array($previousKeys)) {
            throw new RuntimeException(
                'Telegram administrator direct source-message integrity keyring is unavailable.',
            );
        }

        $keys = [$currentKey];
        foreach ($previousKeys as $previousKey) {
            if (! is_string($previousKey) || strlen($previousKey) < 16) {
                throw new RuntimeException(
                    'Telegram administrator direct source-message integrity keyring is unavailable.',
                );
            }
            if (! in_array($previousKey, $keys, true)) {
                $keys[] = $previousKey;
            }
        }

        return $keys;
    }

    /** @param DirectSourceRow $row */
    private function assertStoredActor(object $row, int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new DomainException('Telegram administrator direct source-message record is unavailable.');
        }

        $administratorId = (int) $row->actor_administrator_id;
        $storedUserId = $this->database->connection()
            ->table('administrators')
            ->where('id', $administratorId)
            ->value('user_id');
        if ((! is_int($storedUserId) && ! is_string($storedUserId))
            || (int) $storedUserId !== $actorUserId) {
            throw new DomainException('Telegram administrator direct source-message record is unavailable.');
        }

        return $administratorId;
    }

    private function recipientChatId(string $telegramUserId): int
    {
        $recipientChatId = filter_var(
            $telegramUserId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($recipientChatId === false) {
            throw new DomainException(
                'Telegram administrator direct source-message target is not addressable.',
            );
        }

        return $recipientChatId;
    }

    private function assertTargetMatches(
        TelegramAdministratorCustomerTarget $target,
        string $accountPublicId,
        string $telegramUserId,
    ): void {
        if (! hash_equals($target->accountPublicId, $accountPublicId)
            || ! hash_equals($target->telegramUserId, $telegramUserId)) {
            throw new DomainException(
                'Telegram administrator direct source-message target changed before confirmation.',
            );
        }
    }

    private function linkDeliveryOperation(
        string $publicId,
        int $administratorId,
        string $operationPublicId,
    ): void {
        $this->assertPublicId($publicId, 'Telegram administrator direct source-message identity');
        $this->assertPublicId($operationPublicId, 'Telegram delivery operation identity');
        $connection = $this->database->connection();

        $connection->transaction(function (Connection $connection) use (
            $publicId,
            $administratorId,
            $operationPublicId,
        ): void {
            $row = $this->rowByPublicId($connection, $publicId, true);
            if ($row === null || (int) $row->actor_administrator_id !== $administratorId) {
                throw new DomainException(
                    'Telegram administrator direct source-message record is unavailable.',
                );
            }
            if ($row->delivery_operation_public_id !== null) {
                if (! is_string($row->delivery_operation_public_id)
                    || ! hash_equals($row->delivery_operation_public_id, $operationPublicId)) {
                    throw new RuntimeException(
                        'Telegram administrator direct source-message delivery linkage conflicts with an existing operation.',
                    );
                }

                return;
            }

            $timestamp = $this->formatTime($this->clock->now());
            $updated = $connection->table('telegram_administrator_direct_messages')
                ->where('id', (int) $row->id)
                ->whereNull('delivery_operation_public_id')
                ->update([
                    'delivery_operation_public_id' => $operationPublicId,
                    'queued_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException(
                    'Telegram administrator direct source-message delivery linkage was not persisted.',
                );
            }
        }, 3);
    }

    private function draftTtlSeconds(): int
    {
        $ttl = filter_var(
            $this->config->get('telegram.interaction_session_ttl_seconds'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 86_400]],
        );
        if ($ttl === false) {
            throw new RuntimeException('Telegram administrator direct source-message draft TTL is invalid.');
        }

        return $ttl;
    }

    private function requestHash(string $requestKey): string
    {
        if ($requestKey === '' || strlen($requestKey) > 512) {
            throw new InvalidArgumentException(
                'Telegram administrator direct source-message request key is invalid.',
            );
        }

        return hash('sha256', $requestKey);
    }

    private function assertPublicId(string $publicId, string $label): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function formatTime(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }

    private function parseTime(string $time): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($time, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new RuntimeException(
                'Telegram administrator direct source-message timestamp is invalid.',
            );
        }
    }

    /** @return DirectSourceRow|null */
    private function rowByRequestHash(
        Connection $connection,
        string $requestHash,
        bool $lock,
    ): ?object {
        $query = $connection->table('telegram_administrator_direct_messages')
            ->where('create_request_hash', $requestHash);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DirectSourceRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return DirectSourceRow|null */
    private function rowByPublicId(
        Connection $connection,
        string $publicId,
        bool $lock,
    ): ?object {
        $this->assertPublicId($publicId, 'Telegram administrator direct source-message identity');
        $query = $connection->table('telegram_administrator_direct_messages')
            ->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DirectSourceRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'id', 'public_id', 'create_request_hash', 'actor_administrator_id', 'bot_id',
            'target_account_public_id', 'target_telegram_user_id', 'content_type',
            'content_ciphertext', 'content_integrity_hash', 'content_length', 'media_public_id',
            'media_detected_mime', 'media_byte_size', 'media_content_sha256', 'correlation_id',
            'delivery_operation_public_id', 'expires_at', 'confirmed_at', 'queued_at',
            'created_at', 'updated_at',
        ];
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === 1062 || (string) $exception->getCode() === '23000';
    }
}
