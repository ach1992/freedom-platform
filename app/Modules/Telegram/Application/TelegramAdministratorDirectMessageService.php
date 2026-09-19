<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
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
use SensitiveParameter;
use Throwable;

/**
 * @phpstan-type DirectMessageRow object{
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
 *     inline_keyboard_ciphertext:string|null,
 *     inline_keyboard_hash:string|null,
 *     correlation_id:string,
 *     delivery_operation_public_id:string|null,
 *     expires_at:string,
 *     confirmed_at:string|null,
 *     queued_at:string|null,
 *     created_at:string,
 *     updated_at:string
 * }
 */
final readonly class TelegramAdministratorDirectMessageService
{
    public const PERMISSION = 'telegram.direct_messages.send';

    private const CONTENT_TYPE_TEXT = 'text';

    private const MAX_TEXT_LENGTH = 3500;

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private TelegramAdministratorCustomerTargetDiscovery $targets,
        private StringEncrypter $encrypter,
        private ConfigRepository $config,
        private Clock $clock,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramAdministratorDirectMediaMessageService $mediaMessages,
        private TelegramAdministratorDirectSourceMessageService $sourceMessages,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administrators->allowsUser($actorUserId, self::PERMISSION);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function createTextDraft(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        #[SensitiveParameter] string $text,
        string $requestKey,
    ): TelegramAdministratorDirectMessageDraft {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $validatedText = $this->validatedText($text);
        $requestHash = $this->requestHash($requestKey);
        $connection = $this->database->connection();

        try {
            return $connection->transaction(function (Connection $connection) use (
                $administratorId,
                $botId,
                $target,
                $validatedText,
                $requestHash,
            ): TelegramAdministratorDirectMessageDraft {
                $existing = $this->rowByRequestHash($connection, $requestHash, true);
                if ($existing !== null) {
                    return $this->draftFromRow(
                        $existing,
                        $administratorId,
                        $botId,
                        $target,
                        $validatedText,
                        true,
                    );
                }

                $now = $this->clock->now();
                $publicId = (string) Str::ulid();
                $correlationId = 'tg-admin-dm:'.substr(hash('sha256', $publicId), 0, 40);
                $ciphertext = $this->encrypt($validatedText);
                $timestamp = $this->formatTime($now);
                $expiresAt = $this->formatTime($now->modify('+'.$this->draftTtlSeconds().' seconds'));

                $connection->table('telegram_administrator_direct_messages')->insert([
                    'public_id' => $publicId,
                    'create_request_hash' => $requestHash,
                    'actor_administrator_id' => $administratorId,
                    'bot_id' => $botId,
                    'target_account_public_id' => $target->accountPublicId,
                    'target_telegram_user_id' => $target->telegramUserId,
                    'content_type' => self::CONTENT_TYPE_TEXT,
                    'content_ciphertext' => $ciphertext,
                    'content_integrity_hash' => $this->integrityHash(
                        $publicId,
                        $administratorId,
                        $botId,
                        $target->accountPublicId,
                        $target->telegramUserId,
                        $validatedText,
                    ),
                    'content_length' => mb_strlen($validatedText),
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
                    throw new RuntimeException('Telegram administrator direct-message draft was not persisted.');
                }

                return $this->draftFromRow(
                    $created,
                    $administratorId,
                    $botId,
                    $target,
                    $validatedText,
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
                $validatedText,
                true,
            );
        }
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function createMediaDraft(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $sourceKind,
        TelegramPrivateMediaReceipt $media,
        #[SensitiveParameter] string $caption,
        string $requestKey,
    ): TelegramAdministratorDirectMessageDraft {
        return $this->mediaMessages->createDraft(
            $actorUserId,
            $botId,
            $selectionToken,
            $sourceKind,
            $media,
            $caption,
            $requestKey,
        );
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function createSourceMessageDraft(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        TelegramSourceMessageMode $mode,
        int $sourceChatId,
        int $sourceMessageId,
        string $requestKey,
    ): TelegramAdministratorDirectMessageDraft {
        return $this->sourceMessages->createDraft(
            $actorUserId,
            $botId,
            $selectionToken,
            $mode,
            $sourceChatId,
            $sourceMessageId,
            $requestKey,
        );
    }

    /** @requirement COM-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 */
    public function draftForConfirmation(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramAdministratorDirectMessageDraft {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct-message draft is unavailable.');
        }

        $contentType = (string) $row->content_type;
        if ($contentType === self::CONTENT_TYPE_TEXT) {
            $draft = $this->draftFromRow($row, $administratorId, $botId, $target, null, false);
        } elseif (TelegramSourceMessageMode::tryFrom($contentType) !== null) {
            $draft = $this->sourceMessages->draftForConfirmation(
                $actorUserId,
                $botId,
                $selectionToken,
                $publicId,
            );
        } else {
            $draft = $this->mediaMessages->draftForConfirmation(
                $actorUserId,
                $botId,
                $selectionToken,
                $publicId,
            );
        }

        return $this->withInlineKeyboard($draft, $this->inlineKeyboardFromRow($row));
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function acceptConfirmation(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramAdministratorDirectMessageDraft {
        $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct-message draft is unavailable.');
        }

        $contentType = (string) $row->content_type;
        if ($contentType === self::CONTENT_TYPE_TEXT) {
            $draft = $this->acceptTextConfirmation($actorUserId, $botId, $selectionToken, $publicId);
        } elseif (TelegramSourceMessageMode::tryFrom($contentType) !== null) {
            $draft = $this->sourceMessages->acceptConfirmation(
                $actorUserId,
                $botId,
                $selectionToken,
                $publicId,
            );
        } else {
            $draft = $this->mediaMessages->acceptConfirmation(
                $actorUserId,
                $botId,
                $selectionToken,
                $publicId,
            );
        }

        $current = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($current === null) {
            throw new RuntimeException('Telegram administrator direct-message confirmation disappeared.');
        }

        return $this->withInlineKeyboard($draft, $this->inlineKeyboardFromRow($current));
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function acceptTextConfirmation(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramAdministratorDirectMessageDraft {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
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
                throw new DomainException('Telegram administrator direct-message draft is unavailable.');
            }

            $draft = $this->draftFromRow($row, $administratorId, $botId, $target, null, false);
            if ($row->confirmed_at !== null) {
                return $draft;
            }

            $now = $this->clock->now();
            if ($this->parseTime((string) $row->expires_at) <= $now) {
                throw new DomainException('Telegram administrator direct-message draft has expired.');
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
                throw new RuntimeException('Telegram administrator direct-message confirmation acceptance was not persisted.');
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
    ): TelegramDeliveryOperationReceipt {
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct-message draft is unavailable.');
        }

        $inlineKeyboard = $this->inlineKeyboardFromRow($row);
        $contentType = (string) $row->content_type;
        if (TelegramSourceMessageMode::tryFrom($contentType) !== null) {
            return $this->sourceMessages->confirm(
                $actorUserId,
                $botId,
                $selectionToken,
                $publicId,
                $inlineKeyboard,
            );
        }
        if ($contentType !== self::CONTENT_TYPE_TEXT) {
            return $this->mediaMessages->confirm(
                $actorUserId,
                $botId,
                $selectionToken,
                $publicId,
                $inlineKeyboard,
            );
        }

        return $this->confirmText($actorUserId, $botId, $selectionToken, $publicId);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 OPS-003 QUA-001 QUA-004 */
    public function confirmText(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramDeliveryOperationReceipt {
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct-message draft is unavailable.');
        }
        if ($row->confirmed_at === null) {
            throw new DomainException('Telegram administrator direct-message confirmation has not been accepted.');
        }

        $administratorId = $this->assertStoredActor($row, $actorUserId);
        $draft = $this->draftFromStoredRow($row, $administratorId, $botId, null, false);
        $inlineKeyboard = $this->inlineKeyboardFromRow($row);
        $recipientChatId = $this->recipientChatId($draft->targetTelegramUserId);
        $presentation = $this->presentationForText($draft->text);
        $requestKey = 'tg-admin-direct-message-send:'.$publicId;

        $existing = $this->delivery->findExistingSend(
            $recipientChatId,
            $presentation,
            $requestKey,
            $draft->correlationId,
            $inlineKeyboard,
        );
        if ($existing !== null) {
            $this->linkDeliveryOperation($publicId, $administratorId, $existing->publicId);

            return $existing;
        }

        try {
            $authorizedAdministratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
            if ($authorizedAdministratorId !== $administratorId) {
                throw new RuntimeException('Telegram administrator direct-message actor changed during confirmation.');
            }
            $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
            $this->assertTargetMatches($target, $draft->targetAccountPublicId, $draft->targetTelegramUserId);

            // Re-authorize and re-resolve at the immediate new external-effect boundary.
            $recheckedAdministratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
            if ($recheckedAdministratorId !== $administratorId) {
                throw new RuntimeException('Telegram administrator direct-message actor changed during confirmation.');
            }
            $currentTarget = $this->targets->resolve($actorUserId, $botId, $selectionToken);
            $this->assertTargetMatches($currentTarget, $draft->targetAccountPublicId, $draft->targetTelegramUserId);
        } catch (Throwable $exception) {
            $existing = $this->delivery->findExistingSend(
                $recipientChatId,
                $presentation,
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

        $receipt = $this->delivery->send(
            $recipientChatId,
            $presentation,
            $requestKey,
            $draft->correlationId,
            $inlineKeyboard,
        );

        $this->linkDeliveryOperation($publicId, $administratorId, $receipt->publicId);

        return $receipt;
    }

    /** @requirement COM-001 SEC-002 SEC-003 DAT-002 DAT-003 DAT-004 OPS-003 */
    public function mediaPresentationForDelivery(
        string $publicId,
        int $recipientChatId,
    ): TelegramResolvedPrivateMediaPresentation {
        TelegramPrivateMediaDeliveryProvenanceGuard::assertExecutorCaller();

        return $this->mediaMessages->mediaPresentationForDelivery($publicId, $recipientChatId);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function setInlineKeyboard(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
        TelegramInlineKeyboardSnapshot $inlineKeyboard,
    ): TelegramAdministratorDirectMessageDraft {
        $this->assertAdministratorInlineKeyboard($inlineKeyboard);
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $connection = $this->database->connection();

        $connection->transaction(function (Connection $connection) use (
            $administratorId,
            $botId,
            $target,
            $publicId,
            $inlineKeyboard,
        ): void {
            $row = $this->rowByPublicId($connection, $publicId, true);
            if ($row === null) {
                throw new DomainException('Telegram administrator direct-message draft is unavailable.');
            }
            $this->assertKeyboardDraftEditable($row, $administratorId, $botId, $target, true);

            $existing = $this->inlineKeyboardFromRow($row);
            if ($existing !== null && hash_equals($existing->hash(), $inlineKeyboard->hash())) {
                return;
            }

            $json = $inlineKeyboard->json();
            $timestamp = $this->formatTime($this->clock->now());
            $updated = $connection->table('telegram_administrator_direct_messages')
                ->where('id', (int) $row->id)
                ->whereNull('confirmed_at')
                ->whereNull('delivery_operation_public_id')
                ->update([
                    'inline_keyboard_ciphertext' => $this->encryptKeyboard($json),
                    'inline_keyboard_hash' => $this->keyboardIntegrityHash($publicId, $json),
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException(
                    'Telegram administrator direct-message keyboard was not persisted.',
                );
            }
        }, 3);

        return $this->draftForConfirmation($actorUserId, $botId, $selectionToken, $publicId);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function clearInlineKeyboard(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramAdministratorDirectMessageDraft {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $connection = $this->database->connection();

        $connection->transaction(function (Connection $connection) use (
            $administratorId,
            $botId,
            $target,
            $publicId,
        ): void {
            $row = $this->rowByPublicId($connection, $publicId, true);
            if ($row === null) {
                throw new DomainException('Telegram administrator direct-message draft is unavailable.');
            }
            $this->assertKeyboardDraftEditable($row, $administratorId, $botId, $target, false);

            $existing = $this->inlineKeyboardFromRow($row);
            if ($existing === null) {
                return;
            }

            $timestamp = $this->formatTime($this->clock->now());
            $updated = $connection->table('telegram_administrator_direct_messages')
                ->where('id', (int) $row->id)
                ->whereNull('confirmed_at')
                ->whereNull('delivery_operation_public_id')
                ->update([
                    'inline_keyboard_ciphertext' => null,
                    'inline_keyboard_hash' => null,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException(
                    'Telegram administrator direct-message keyboard removal was not persisted.',
                );
            }
        }, 3);

        return $this->draftForConfirmation($actorUserId, $botId, $selectionToken, $publicId);
    }

    /** @requirement COM-001 SEC-002 SEC-003 DAT-002 DAT-003 OPS-003 */
    public function sourceMessagePresentationForDelivery(
        string $publicId,
        int $recipientChatId,
    ): TelegramResolvedSourceMessagePresentation {
        TelegramSourceMessageDeliveryProvenanceGuard::assertExecutorCaller();

        return $this->sourceMessages->presentationForDelivery($publicId, $recipientChatId);
    }

    /** @requirement COM-001 ACL-002 SEC-002 DAT-003 OPS-003 */
    public function deliveryResultForSender(
        int $actorUserId,
        string $publicId,
    ): TelegramAdministratorDirectMessageDeliveryResult {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $connection = $this->database->connection();
        $row = $this->rowByPublicId($connection, $publicId, false);
        if ($row === null || (int) $row->actor_administrator_id !== $administratorId) {
            throw new DomainException('Telegram administrator direct-message record is unavailable.');
        }
        $operationPublicId = $row->delivery_operation_public_id;
        if (! is_string($operationPublicId) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $operationPublicId) !== 1) {
            throw new DomainException('Telegram administrator direct-message delivery has not been queued.');
        }

        $operation = $connection->table('telegram_delivery_operations')
            ->where('public_id', $operationPublicId)
            ->first(['state', 'telegram_message_id', 'result_code', 'correlation_id']);
        if ($operation === null) {
            throw new RuntimeException('Telegram administrator direct-message delivery operation is unavailable.');
        }
        $state = TelegramDeliveryOperationState::tryFrom((string) $operation->state);
        if ($state === null) {
            throw new RuntimeException('Telegram administrator direct-message delivery state is invalid.');
        }
        $messageId = $operation->telegram_message_id;
        if ($messageId !== null && ! is_int($messageId) && ! (is_string($messageId) && ctype_digit($messageId))) {
            throw new RuntimeException('Telegram administrator direct-message result message ID is invalid.');
        }
        $resultCode = $operation->result_code;
        if ($resultCode !== null && ! is_string($resultCode)) {
            throw new RuntimeException('Telegram administrator direct-message result code is invalid.');
        }

        return new TelegramAdministratorDirectMessageDeliveryResult(
            $publicId,
            $operationPublicId,
            $state,
            $messageId === null ? null : (int) $messageId,
            $resultCode,
            (string) $operation->correlation_id,
        );
    }

    private function assertAdministratorInlineKeyboard(TelegramInlineKeyboardSnapshot $inlineKeyboard): void
    {
        $rows = $inlineKeyboard->rows();
        if (count($rows) > 8) {
            throw new DomainException(
                'Telegram administrator direct-message keyboard exceeds the supported button limit.',
            );
        }

        foreach ($rows as $row) {
            if (count($row) !== 1) {
                throw new DomainException(
                    'Telegram administrator direct-message keyboard supports one safe URL button per row.',
                );
            }

            $button = $row[0];
            if (! $button instanceof TelegramInlineHttpsUrlButton
                || $button->purpose !== TelegramInlineHttpsUrlPurpose::SupportContact
                || $button->style !== null
                || preg_match('/[\x00-\x1F\x7F]/u', $button->text) === 1) {
                throw new DomainException(
                    'Telegram administrator direct-message keyboard contains an unsupported action.',
                );
            }
        }
    }

    /** @param DirectMessageRow $row */
    private function assertKeyboardDraftEditable(
        object $row,
        int $administratorId,
        string $botId,
        TelegramAdministratorCustomerTarget $target,
        bool $adding,
    ): void {
        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || ! hash_equals((string) $row->target_account_public_id, $target->accountPublicId)
            || ! hash_equals((string) $row->target_telegram_user_id, $target->telegramUserId)
            || $row->confirmed_at !== null
            || $row->delivery_operation_public_id !== null
            || $this->parseTime((string) $row->expires_at) <= $this->clock->now()) {
            throw new DomainException(
                'Telegram administrator direct-message keyboard draft is no longer editable.',
            );
        }

        if ($adding && (string) $row->content_type === TelegramSourceMessageMode::Forward->value) {
            throw new DomainException('Telegram forward direct messages do not support authored buttons.');
        }
    }

    /** @param DirectMessageRow $row */
    private function inlineKeyboardFromRow(object $row): ?TelegramInlineKeyboardSnapshot
    {
        $ciphertext = $row->inline_keyboard_ciphertext;
        $storedHash = $row->inline_keyboard_hash;
        if ($ciphertext === null && $storedHash === null) {
            return null;
        }
        if (! is_string($ciphertext) || ! is_string($storedHash)) {
            throw new DomainException(
                'Telegram administrator direct-message keyboard integrity validation failed.',
            );
        }

        $json = $this->decryptKeyboard($ciphertext);
        if (! $this->keyboardIntegrityHashMatches($storedHash, (string) $row->public_id, $json)) {
            throw new DomainException(
                'Telegram administrator direct-message keyboard integrity validation failed.',
            );
        }

        try {
            $inlineKeyboard = TelegramInlineKeyboardSnapshot::restore($json);
        } catch (InvalidArgumentException) {
            throw new DomainException(
                'Telegram administrator direct-message keyboard integrity validation failed.',
            );
        }

        $this->assertAdministratorInlineKeyboard($inlineKeyboard);
        if ((string) $row->content_type === TelegramSourceMessageMode::Forward->value) {
            throw new DomainException(
                'Telegram forward direct-message record cannot carry authored buttons.',
            );
        }

        return $inlineKeyboard;
    }

    private function withInlineKeyboard(
        TelegramAdministratorDirectMessageDraft $draft,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard,
    ): TelegramAdministratorDirectMessageDraft {
        return new TelegramAdministratorDirectMessageDraft(
            $draft->publicId,
            $draft->targetAccountPublicId,
            $draft->targetTelegramUserId,
            $draft->text,
            $draft->correlationId,
            $draft->replayed,
            $draft->contentType,
            $draft->mediaPublicId,
            $draft->mediaDetectedMime,
            $draft->mediaByteSize,
            $draft->mediaContentSha256,
            $draft->sourceChatId,
            $draft->sourceMessageId,
            $inlineKeyboard,
        );
    }

    private function encryptKeyboard(string $json): string
    {
        if ($json === '' || strlen($json) > 16_384) {
            throw new DomainException('Telegram administrator direct-message keyboard snapshot is invalid.');
        }

        try {
            $ciphertext = $this->encrypter->encryptString($json);
        } catch (Throwable) {
            throw new RuntimeException(
                'Telegram administrator direct-message keyboard could not be encrypted.',
            );
        }

        if ($ciphertext === '' || strlen($ciphertext) > 65_536 || hash_equals($ciphertext, $json)) {
            throw new RuntimeException(
                'Telegram administrator direct-message keyboard ciphertext is invalid.',
            );
        }

        return $ciphertext;
    }

    private function decryptKeyboard(string $ciphertext): string
    {
        try {
            $json = $this->encrypter->decryptString($ciphertext);
        } catch (Throwable) {
            throw new DomainException(
                'Telegram administrator direct-message keyboard integrity validation failed.',
            );
        }

        if ($json === '' || strlen($json) > 16_384 || str_contains($json, "\0")) {
            throw new DomainException(
                'Telegram administrator direct-message keyboard integrity validation failed.',
            );
        }

        return $json;
    }

    private function keyboardIntegrityHash(string $publicId, string $json): string
    {
        return $this->keyboardIntegrityHashWithKey($publicId, $json, $this->integrityKeys()[0]);
    }

    private function keyboardIntegrityHashMatches(
        string $storedHash,
        string $publicId,
        string $json,
    ): bool {
        if (preg_match('/\A[0-9a-f]{64}\z/', $storedHash) !== 1) {
            return false;
        }

        foreach ($this->integrityKeys() as $key) {
            if (hash_equals(
                $storedHash,
                $this->keyboardIntegrityHashWithKey($publicId, $json, $key),
            )) {
                return true;
            }
        }

        return false;
    }

    private function keyboardIntegrityHashWithKey(
        string $publicId,
        string $json,
        #[SensitiveParameter] string $key,
    ): string {
        return hash_hmac(
            'sha256',
            implode("\0", [
                'telegram-admin-direct-message-keyboard-v1',
                $publicId,
                $json,
            ]),
            $key,
        );
    }

    /** @param DirectMessageRow $row */
    private function assertStoredActor(object $row, int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new DomainException('Telegram administrator direct-message record is unavailable.');
        }

        $administratorId = (int) $row->actor_administrator_id;
        $storedUserId = $this->database->connection()
            ->table('administrators')
            ->where('id', $administratorId)
            ->value('user_id');
        if ((! is_int($storedUserId) && ! is_string($storedUserId))
            || (int) $storedUserId !== $actorUserId) {
            throw new DomainException('Telegram administrator direct-message record is unavailable.');
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
            throw new DomainException('Telegram administrator direct-message target is not addressable.');
        }

        return $recipientChatId;
    }

    private function presentationForText(#[SensitiveParameter] string $text): ConfidentialTelegramPresentation
    {
        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(#[SensitiveParameter] private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };

        return $this->presentations->fromSource($source);
    }

    private function linkDeliveryOperation(string $publicId, int $administratorId, string $operationPublicId): void
    {
        $this->assertPublicId($publicId, 'Telegram administrator direct-message identity');
        $this->assertPublicId($operationPublicId, 'Telegram delivery operation identity');
        $connection = $this->database->connection();

        $connection->transaction(function (Connection $connection) use ($publicId, $administratorId, $operationPublicId): void {
            $row = $this->rowByPublicId($connection, $publicId, true);
            if ($row === null || (int) $row->actor_administrator_id !== $administratorId) {
                throw new DomainException('Telegram administrator direct-message record is unavailable.');
            }
            if ($row->delivery_operation_public_id !== null) {
                if (! is_string($row->delivery_operation_public_id)
                    || ! hash_equals($row->delivery_operation_public_id, $operationPublicId)) {
                    throw new RuntimeException('Telegram administrator direct-message delivery linkage conflicts with an existing operation.');
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
                throw new RuntimeException('Telegram administrator direct-message delivery linkage was not persisted.');
            }
        }, 3);
    }

    /** @param DirectMessageRow $row */
    private function draftFromRow(
        object $row,
        int $administratorId,
        string $botId,
        TelegramAdministratorCustomerTarget $target,
        ?string $expectedText,
        bool $replayed,
    ): TelegramAdministratorDirectMessageDraft {
        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || ! hash_equals((string) $row->target_account_public_id, $target->accountPublicId)
            || ! hash_equals((string) $row->target_telegram_user_id, $target->telegramUserId)
            || (string) $row->content_type !== self::CONTENT_TYPE_TEXT) {
            throw new DomainException('Telegram administrator direct-message draft does not match the current actor or target.');
        }

        return $this->draftFromStoredRow($row, $administratorId, $botId, $expectedText, $replayed);
    }

    /** @param DirectMessageRow $row */
    private function draftFromStoredRow(
        object $row,
        int $administratorId,
        string $botId,
        ?string $expectedText,
        bool $replayed,
    ): TelegramAdministratorDirectMessageDraft {
        $publicId = (string) $row->public_id;
        $targetAccountPublicId = (string) $row->target_account_public_id;
        $targetTelegramUserId = (string) $row->target_telegram_user_id;
        $this->assertPublicId($publicId, 'Telegram administrator direct-message identity');
        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || (string) $row->content_type !== self::CONTENT_TYPE_TEXT) {
            throw new DomainException('Telegram administrator direct-message draft does not match the stored actor or bot.');
        }
        if ($row->confirmed_at === null
            && $this->parseTime((string) $row->expires_at) <= $this->clock->now()) {
            throw new DomainException('Telegram administrator direct-message draft has expired.');
        }
        if ($row->confirmed_at !== null) {
            if (! is_string($row->confirmed_at)) {
                throw new RuntimeException('Telegram administrator direct-message confirmation timestamp is invalid.');
            }
            $confirmedAt = $this->parseTime($row->confirmed_at);
            if ($confirmedAt < $this->parseTime((string) $row->created_at)
                || $confirmedAt >= $this->parseTime((string) $row->expires_at)) {
                throw new DomainException('Telegram administrator direct-message confirmation evidence is invalid.');
            }
        }

        $text = $this->decrypt((string) $row->content_ciphertext);
        if ((int) $row->content_length !== mb_strlen($text)
            || ! $this->integrityHashMatches(
                (string) $row->content_integrity_hash,
                $publicId,
                $administratorId,
                $botId,
                $targetAccountPublicId,
                $targetTelegramUserId,
                $text,
            )) {
            throw new DomainException('Telegram administrator direct-message content integrity validation failed.');
        }
        if ($expectedText !== null && ! hash_equals($expectedText, $text)) {
            throw new RuntimeException('Telegram administrator direct-message idempotency key conflicts with different content.');
        }

        return new TelegramAdministratorDirectMessageDraft(
            $publicId,
            $targetAccountPublicId,
            $targetTelegramUserId,
            $text,
            (string) $row->correlation_id,
            $replayed,
        );
    }

    private function assertTargetMatches(
        TelegramAdministratorCustomerTarget $target,
        string $accountPublicId,
        string $telegramUserId,
    ): void {
        if (! hash_equals($target->accountPublicId, $accountPublicId)
            || ! hash_equals($target->telegramUserId, $telegramUserId)) {
            throw new DomainException('Telegram administrator direct-message target changed before confirmation.');
        }
    }

    private function validatedText(#[SensitiveParameter] string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new DomainException('Telegram administrator direct-message text must be valid UTF-8.');
        }
        if (trim($text) === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new DomainException('Telegram administrator direct-message text length is invalid.');
        }

        return $text;
    }

    private function encrypt(#[SensitiveParameter] string $text): string
    {
        try {
            $ciphertext = $this->encrypter->encryptString($text);
        } catch (Throwable) {
            throw new RuntimeException('Telegram administrator direct-message text could not be encrypted.');
        }
        if ($ciphertext === '' || strlen($ciphertext) > 65_536 || hash_equals($ciphertext, $text)) {
            throw new RuntimeException('Telegram administrator direct-message ciphertext is invalid.');
        }

        return $ciphertext;
    }

    private function decrypt(#[SensitiveParameter] string $ciphertext): string
    {
        try {
            $text = $this->encrypter->decryptString($ciphertext);
        } catch (Throwable) {
            throw new DomainException('Telegram administrator direct-message content integrity validation failed.');
        }
        if (trim($text) === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new DomainException('Telegram administrator direct-message content integrity validation failed.');
        }

        return $text;
    }

    private function integrityHash(
        string $publicId,
        int $administratorId,
        string $botId,
        string $targetAccountPublicId,
        string $targetTelegramUserId,
        #[SensitiveParameter] string $text,
    ): string {
        return $this->integrityHashWithKey(
            $publicId,
            $administratorId,
            $botId,
            $targetAccountPublicId,
            $targetTelegramUserId,
            $text,
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
        #[SensitiveParameter] string $text,
    ): bool {
        if (preg_match('/\\A[0-9a-f]{64}\\z/', $storedHash) !== 1) {
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
                    $text,
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
        #[SensitiveParameter] string $text,
        #[SensitiveParameter] string $key,
    ): string {
        return hash_hmac(
            'sha256',
            implode("\0", [
                'telegram-admin-direct-message-v1',
                $publicId,
                (string) $administratorId,
                $botId,
                $targetAccountPublicId,
                $targetTelegramUserId,
                $text,
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
            throw new RuntimeException('Telegram administrator direct-message integrity keyring is unavailable.');
        }

        $keys = [$currentKey];
        foreach ($previousKeys as $previousKey) {
            if (! is_string($previousKey) || strlen($previousKey) < 16) {
                throw new RuntimeException('Telegram administrator direct-message integrity keyring is unavailable.');
            }
            if (! in_array($previousKey, $keys, true)) {
                $keys[] = $previousKey;
            }
        }

        return $keys;
    }

    private function draftTtlSeconds(): int
    {
        $ttl = filter_var(
            $this->config->get('telegram.interaction_session_ttl_seconds'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 86_400]],
        );
        if ($ttl === false) {
            throw new RuntimeException('Telegram administrator direct-message draft TTL is invalid.');
        }

        return $ttl;
    }

    private function requestHash(string $requestKey): string
    {
        if ($requestKey === '' || strlen($requestKey) > 512) {
            throw new InvalidArgumentException('Telegram administrator direct-message request key is invalid.');
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
            throw new RuntimeException('Telegram administrator direct-message timestamp is invalid.');
        }
    }

    /** @return DirectMessageRow|null */
    private function rowByRequestHash(Connection $connection, string $requestHash, bool $lock): ?object
    {
        $query = $connection->table('telegram_administrator_direct_messages')->where('create_request_hash', $requestHash);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DirectMessageRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return DirectMessageRow|null */
    private function rowByPublicId(Connection $connection, string $publicId, bool $lock): ?object
    {
        $this->assertPublicId($publicId, 'Telegram administrator direct-message identity');
        $query = $connection->table('telegram_administrator_direct_messages')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DirectMessageRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'id', 'public_id', 'create_request_hash', 'actor_administrator_id', 'bot_id',
            'target_account_public_id', 'target_telegram_user_id', 'content_type', 'content_ciphertext',
            'content_integrity_hash', 'content_length', 'inline_keyboard_ciphertext', 'inline_keyboard_hash',
            'correlation_id', 'delivery_operation_public_id', 'expires_at', 'confirmed_at', 'queued_at',
            'created_at', 'updated_at',
        ];
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === 1062 || (string) $exception->getCode() === '23000';
    }
}
