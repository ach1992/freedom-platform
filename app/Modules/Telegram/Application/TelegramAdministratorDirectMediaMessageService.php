<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
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
 * @phpstan-type DirectMediaRow object{
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
final readonly class TelegramAdministratorDirectMediaMessageService
{
    private const CONTENT_TYPES = ['photo', 'video', 'document'];

    private const MAX_CAPTION_LENGTH = 1024;

    private const MAX_PHOTO_BYTES = 10_000_000;

    private const MAX_PRIVATE_MEDIA_BYTES = 20_000_000;

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private TelegramAdministratorCustomerTargetDiscovery $targets,
        private StringEncrypter $encrypter,
        private ConfigRepository $config,
        private Clock $clock,
        private TelegramPrivateMediaIngestor $privateMedia,
        private TelegramPrivateMediaDeliveryResolver $privateMediaResolver,
        private TelegramPrivateMediaDeliveryQueue $delivery,
    ) {}

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function createDraft(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $sourceKind,
        TelegramPrivateMediaReceipt $media,
        #[SensitiveParameter] string $caption,
        string $requestKey,
    ): TelegramAdministratorDirectMessageDraft {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramAdministratorDirectMessageService::PERMISSION,
        );
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $contentType = $this->validatedContentType($sourceKind, $media);
        $validatedCaption = $this->validatedCaption($contentType, $caption);
        $requestHash = $this->requestHash($requestKey);
        $connection = $this->database->connection();

        try {
            return $connection->transaction(function (Connection $connection) use (
                $actorUserId,
                $administratorId,
                $botId,
                $target,
                $contentType,
                $media,
                $validatedCaption,
                $requestHash,
            ): TelegramAdministratorDirectMessageDraft {
                $existing = $this->rowByRequestHash($connection, $requestHash, true);
                if ($existing !== null) {
                    $draft = $this->draftFromRow(
                        $existing,
                        $administratorId,
                        $botId,
                        $target,
                        $contentType,
                        $media,
                        $validatedCaption,
                        true,
                    );
                    $this->privateMedia->associate(
                        $media,
                        $actorUserId,
                        'administrator_direct_message',
                        $draft->publicId,
                    );

                    return $draft;
                }

                $now = $this->clock->now();
                $publicId = strtoupper((string) Str::ulid());
                $correlationId = 'tg-admin-dm:'.substr(hash('sha256', $publicId), 0, 40);
                $timestamp = $this->formatTime($now);
                $expiresAt = $this->formatTime($now->modify('+'.$this->draftTtlSeconds().' seconds'));

                $connection->table('telegram_administrator_direct_messages')->insert([
                    'public_id' => $publicId,
                    'create_request_hash' => $requestHash,
                    'actor_administrator_id' => $administratorId,
                    'bot_id' => $botId,
                    'target_account_public_id' => $target->accountPublicId,
                    'target_telegram_user_id' => $target->telegramUserId,
                    'content_type' => $contentType,
                    'content_ciphertext' => $this->encryptCaption($validatedCaption),
                    'content_integrity_hash' => $this->integrityHash(
                        $publicId,
                        $administratorId,
                        $botId,
                        $target->accountPublicId,
                        $target->telegramUserId,
                        $contentType,
                        $validatedCaption,
                        $media,
                    ),
                    'content_length' => mb_strlen($validatedCaption),
                    'media_public_id' => strtoupper($media->publicId),
                    'media_detected_mime' => $media->detectedMime,
                    'media_byte_size' => $media->byteSize,
                    'media_content_sha256' => strtolower($media->contentSha256),
                    'correlation_id' => $correlationId,
                    'delivery_operation_public_id' => null,
                    'expires_at' => $expiresAt,
                    'confirmed_at' => null,
                    'queued_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $this->privateMedia->associate(
                    $media,
                    $actorUserId,
                    'administrator_direct_message',
                    $publicId,
                );

                $created = $this->rowByRequestHash($connection, $requestHash, true);
                if ($created === null) {
                    throw new RuntimeException('Telegram administrator direct-media draft was not persisted.');
                }

                return $this->draftFromRow(
                    $created,
                    $administratorId,
                    $botId,
                    $target,
                    $contentType,
                    $media,
                    $validatedCaption,
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

            $draft = $this->draftFromRow(
                $existing,
                $administratorId,
                $botId,
                $target,
                $contentType,
                $media,
                $validatedCaption,
                true,
            );
            $this->privateMedia->associate(
                $media,
                $actorUserId,
                'administrator_direct_message',
                $draft->publicId,
            );

            return $draft;
        }
    }

    /** @requirement COM-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 DAT-004 */
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
            throw new DomainException('Telegram administrator direct-media draft is unavailable.');
        }

        return $this->draftFromRow($row, $administratorId, $botId, $target, null, null, null, false);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
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
                throw new DomainException('Telegram administrator direct-media draft is unavailable.');
            }
            $draft = $this->draftFromRow($row, $administratorId, $botId, $target, null, null, null, false);
            if ($row->confirmed_at !== null) {
                return $draft;
            }

            $now = $this->clock->now();
            if ($this->parseTime((string) $row->expires_at) <= $now) {
                throw new DomainException('Telegram administrator direct-media draft has expired.');
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
                throw new RuntimeException('Telegram administrator direct-media confirmation acceptance was not persisted.');
            }

            return $draft;
        }, 3);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 DAT-004 OPS-003 QUA-001 QUA-004 */
    public function confirm(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramDeliveryOperationReceipt {
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct-media draft is unavailable.');
        }
        if ($row->confirmed_at === null) {
            throw new DomainException('Telegram administrator direct-media confirmation has not been accepted.');
        }

        $administratorId = $this->assertStoredActor($row, $actorUserId);
        $draft = $this->draftFromStoredRow($row, $administratorId, $botId, null, null, null, false);
        $recipientChatId = $this->recipientChatId($draft->targetTelegramUserId);
        $reference = TelegramPrivateMediaPresentationReference::administratorDirectMessage($publicId);
        $requestKey = 'tg-admin-direct-message-send:'.$publicId;

        $existing = $this->delivery->findExistingSend(
            $recipientChatId,
            $reference,
            $requestKey,
            $draft->correlationId,
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
                throw new RuntimeException('Telegram administrator direct-media actor changed during confirmation.');
            }
            $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
            $this->assertTargetMatches($target, $draft->targetAccountPublicId, $draft->targetTelegramUserId);

            $recheckedAdministratorId = $this->administrators->authorizeUser(
                $actorUserId,
                TelegramAdministratorDirectMessageService::PERMISSION,
            );
            if ($recheckedAdministratorId !== $administratorId) {
                throw new RuntimeException('Telegram administrator direct-media actor changed during confirmation.');
            }
            $currentTarget = $this->targets->resolve($actorUserId, $botId, $selectionToken);
            $this->assertTargetMatches($currentTarget, $draft->targetAccountPublicId, $draft->targetTelegramUserId);
        } catch (Throwable $exception) {
            $existing = $this->delivery->findExistingSend(
                $recipientChatId,
                $reference,
                $requestKey,
                $draft->correlationId,
            );
            if ($existing !== null) {
                $this->linkDeliveryOperation($publicId, $administratorId, $existing->publicId);

                return $existing;
            }

            throw $exception;
        }

        $receipt = $this->delivery->send(
            $recipientChatId,
            $reference,
            $requestKey,
            $draft->correlationId,
        );
        $this->linkDeliveryOperation($publicId, $administratorId, $receipt->publicId);

        return $receipt;
    }

    /** @requirement COM-001 SEC-002 SEC-003 DAT-002 DAT-003 DAT-004 OPS-003 */
    public function mediaPresentationForDelivery(
        string $publicId,
        int $recipientChatId,
    ): TelegramResolvedPrivateMediaPresentation {
        TelegramPresentationProvenanceGuard::assertExactInternalCaller(
            TelegramAdministratorDirectMessageService::class,
            __DIR__.'/TelegramAdministratorDirectMessageService.php',
        );

        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null || $row->confirmed_at === null) {
            throw new DomainException('Telegram administrator direct-media delivery reference is unavailable.');
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
            || ! $draft->isMedia()
            || $draft->mediaPublicId === null
            || $draft->mediaDetectedMime === null
            || $draft->mediaByteSize === null
            || $draft->mediaContentSha256 === null) {
            throw new DomainException('Telegram administrator direct-media delivery target is invalid.');
        }

        $payload = $this->privateMediaResolver->resolveAdministratorDirectMessage(
            $draft->mediaPublicId,
            $draft->publicId,
        );
        $bytes = $payload->bytes();
        if ($payload->detectedMime !== $draft->mediaDetectedMime
            || $payload->byteSize !== $draft->mediaByteSize
            || ! hash_equals($draft->mediaContentSha256, hash('sha256', $bytes))) {
            throw new DomainException('Telegram administrator direct-media delivery evidence changed.');
        }

        return new TelegramResolvedPrivateMediaPresentation(
            $draft->contentType,
            $bytes,
            $this->filename($draft->publicId, $draft->mediaDetectedMime),
            $draft->text,
            $draft->mediaDetectedMime,
            $draft->mediaByteSize,
            $draft->mediaContentSha256,
        );
    }

    /** @param DirectMediaRow $row */
    private function draftFromRow(
        object $row,
        int $administratorId,
        string $botId,
        TelegramAdministratorCustomerTarget $target,
        ?string $expectedContentType,
        ?TelegramPrivateMediaReceipt $expectedMedia,
        ?string $expectedCaption,
        bool $replayed,
    ): TelegramAdministratorDirectMessageDraft {
        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || ! hash_equals((string) $row->target_account_public_id, $target->accountPublicId)
            || ! hash_equals((string) $row->target_telegram_user_id, $target->telegramUserId)) {
            throw new DomainException('Telegram administrator direct-media draft does not match the current actor or target.');
        }

        return $this->draftFromStoredRow(
            $row,
            $administratorId,
            $botId,
            $expectedContentType,
            $expectedMedia,
            $expectedCaption,
            $replayed,
        );
    }

    /** @param DirectMediaRow $row */
    private function draftFromStoredRow(
        object $row,
        int $administratorId,
        string $botId,
        ?string $expectedContentType,
        ?TelegramPrivateMediaReceipt $expectedMedia,
        ?string $expectedCaption,
        bool $replayed,
    ): TelegramAdministratorDirectMessageDraft {
        $publicId = (string) $row->public_id;
        $targetAccountPublicId = (string) $row->target_account_public_id;
        $targetTelegramUserId = (string) $row->target_telegram_user_id;
        $contentType = (string) $row->content_type;
        $this->assertPublicId($publicId, 'Telegram administrator direct-media identity');

        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || ! in_array($contentType, self::CONTENT_TYPES, true)) {
            throw new DomainException('Telegram administrator direct-media draft does not match the stored actor or bot.');
        }
        if ($row->confirmed_at === null
            && $this->parseTime((string) $row->expires_at) <= $this->clock->now()) {
            throw new DomainException('Telegram administrator direct-media draft has expired.');
        }
        if ($row->confirmed_at !== null) {
            if (! is_string($row->confirmed_at)) {
                throw new RuntimeException('Telegram administrator direct-media confirmation timestamp is invalid.');
            }
            $confirmedAt = $this->parseTime($row->confirmed_at);
            if ($confirmedAt < $this->parseTime((string) $row->created_at)
                || $confirmedAt >= $this->parseTime((string) $row->expires_at)) {
                throw new DomainException('Telegram administrator direct-media confirmation evidence is invalid.');
            }
        }

        $caption = $this->decryptCaption((string) $row->content_ciphertext);
        $mediaPublicId = is_string($row->media_public_id) ? strtoupper($row->media_public_id) : null;
        $mediaDetectedMime = is_string($row->media_detected_mime) ? $row->media_detected_mime : null;
        $mediaByteSize = $row->media_byte_size === null ? null : (int) $row->media_byte_size;
        $mediaContentSha256 = is_string($row->media_content_sha256)
            ? strtolower($row->media_content_sha256)
            : null;

        if ((int) $row->content_length !== mb_strlen($caption)
            || $mediaPublicId === null
            || $mediaDetectedMime === null
            || $mediaByteSize === null
            || $mediaContentSha256 === null
            || ! $this->mediaShapeIsValid(
                $contentType,
                $caption,
                $mediaPublicId,
                $mediaDetectedMime,
                $mediaByteSize,
                $mediaContentSha256,
            )
            || ! $this->integrityHashMatches(
                (string) $row->content_integrity_hash,
                $publicId,
                $administratorId,
                $botId,
                $targetAccountPublicId,
                $targetTelegramUserId,
                $contentType,
                $caption,
                $mediaPublicId,
                $mediaDetectedMime,
                $mediaByteSize,
                $mediaContentSha256,
            )) {
            throw new DomainException('Telegram administrator direct-media content integrity validation failed.');
        }

        if ($expectedContentType !== null && ! hash_equals($expectedContentType, $contentType)) {
            throw new RuntimeException('Telegram administrator direct-media idempotency key conflicts with content type.');
        }
        if ($expectedCaption !== null && ! hash_equals($expectedCaption, $caption)) {
            throw new RuntimeException('Telegram administrator direct-media idempotency key conflicts with caption.');
        }
        if ($expectedMedia !== null
            && (! hash_equals(strtoupper($expectedMedia->publicId), $mediaPublicId)
                || ! hash_equals($expectedMedia->detectedMime, $mediaDetectedMime)
                || $expectedMedia->byteSize !== $mediaByteSize
                || ! hash_equals(strtolower($expectedMedia->contentSha256), $mediaContentSha256))) {
            throw new RuntimeException('Telegram administrator direct-media idempotency key conflicts with media.');
        }

        return new TelegramAdministratorDirectMessageDraft(
            $publicId,
            $targetAccountPublicId,
            $targetTelegramUserId,
            $caption,
            (string) $row->correlation_id,
            $replayed,
            $contentType,
            $mediaPublicId,
            $mediaDetectedMime,
            $mediaByteSize,
            $mediaContentSha256,
        );
    }

    private function validatedContentType(
        string $sourceKind,
        TelegramPrivateMediaReceipt $media,
    ): string {
        if (! in_array($sourceKind, self::CONTENT_TYPES, true)
            || ! Str::isUlid($media->publicId)
            || ! TelegramPrivateMediaContentValidator::isApprovedMime($media->detectedMime)
            || $media->byteSize < 1
            || $media->byteSize > self::MAX_PRIVATE_MEDIA_BYTES
            || preg_match('/\A[0-9a-f]{64}\z/', strtolower($media->contentSha256)) !== 1
            || ($sourceKind === 'photo' && $media->byteSize > self::MAX_PHOTO_BYTES)
            || ($sourceKind === 'photo' && ! TelegramPrivateMediaContentValidator::isImageMime($media->detectedMime))
            || ($sourceKind === 'video' && $media->detectedMime !== 'video/mp4')) {
            throw new DomainException('Telegram administrator direct-media input is invalid.');
        }

        return $sourceKind;
    }

    private function validatedCaption(string $contentType, #[SensitiveParameter] string $caption): string
    {
        if (! mb_check_encoding($caption, 'UTF-8')
            || str_contains($caption, "\0")
            || mb_strlen($caption) > self::MAX_CAPTION_LENGTH
            || ($contentType !== 'photo' && $caption !== '')) {
            throw new DomainException('Telegram administrator direct-media caption is invalid.');
        }

        return $caption;
    }

    private function mediaShapeIsValid(
        string $contentType,
        string $caption,
        string $mediaPublicId,
        string $mime,
        int $byteSize,
        string $sha256,
    ): bool {
        return in_array($contentType, self::CONTENT_TYPES, true)
            && Str::isUlid($mediaPublicId)
            && TelegramPrivateMediaContentValidator::isApprovedMime($mime)
            && $byteSize >= 1
            && $byteSize <= self::MAX_PRIVATE_MEDIA_BYTES
            && ($contentType !== 'photo' || $byteSize <= self::MAX_PHOTO_BYTES)
            && preg_match('/\A[0-9a-f]{64}\z/', $sha256) === 1
            && mb_check_encoding($caption, 'UTF-8')
            && ! str_contains($caption, "\0")
            && mb_strlen($caption) <= self::MAX_CAPTION_LENGTH
            && ($contentType === 'photo' ? TelegramPrivateMediaContentValidator::isImageMime($mime) : true)
            && ($contentType === 'video' ? $mime === 'video/mp4' : true)
            && ($contentType === 'photo' || $caption === '');
    }

    /** @param DirectMediaRow $row */
    private function assertStoredActor(object $row, int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new DomainException('Telegram administrator direct-media record is unavailable.');
        }
        $administratorId = (int) $row->actor_administrator_id;
        $storedUserId = $this->database->connection()
            ->table('administrators')
            ->where('id', $administratorId)
            ->value('user_id');
        if ((! is_int($storedUserId) && ! is_string($storedUserId))
            || (int) $storedUserId !== $actorUserId) {
            throw new DomainException('Telegram administrator direct-media record is unavailable.');
        }

        return $administratorId;
    }

    private function assertTargetMatches(
        TelegramAdministratorCustomerTarget $target,
        string $accountPublicId,
        string $telegramUserId,
    ): void {
        if (! hash_equals($target->accountPublicId, $accountPublicId)
            || ! hash_equals($target->telegramUserId, $telegramUserId)) {
            throw new DomainException('Telegram administrator direct-media target changed before confirmation.');
        }
    }

    private function recipientChatId(string $telegramUserId): int
    {
        $recipientChatId = filter_var(
            $telegramUserId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($recipientChatId === false) {
            throw new DomainException('Telegram administrator direct-media target is not addressable.');
        }

        return (int) $recipientChatId;
    }

    private function encryptCaption(#[SensitiveParameter] string $caption): string
    {
        try {
            $ciphertext = $this->encrypter->encryptString($caption);
        } catch (Throwable) {
            throw new RuntimeException('Telegram administrator direct-media caption could not be encrypted.');
        }
        if ($ciphertext === '' || strlen($ciphertext) > 65_536 || ($caption !== '' && hash_equals($ciphertext, $caption))) {
            throw new RuntimeException('Telegram administrator direct-media ciphertext is invalid.');
        }

        return $ciphertext;
    }

    private function decryptCaption(#[SensitiveParameter] string $ciphertext): string
    {
        try {
            $caption = $this->encrypter->decryptString($ciphertext);
        } catch (Throwable) {
            throw new DomainException('Telegram administrator direct-media content integrity validation failed.');
        }
        if (! mb_check_encoding($caption, 'UTF-8')
            || str_contains($caption, "\0")
            || mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
            throw new DomainException('Telegram administrator direct-media content integrity validation failed.');
        }

        return $caption;
    }

    private function integrityHash(
        string $publicId,
        int $administratorId,
        string $botId,
        string $targetAccountPublicId,
        string $targetTelegramUserId,
        string $contentType,
        #[SensitiveParameter] string $caption,
        TelegramPrivateMediaReceipt $media,
    ): string {
        return $this->integrityHashWithKey(
            $publicId,
            $administratorId,
            $botId,
            $targetAccountPublicId,
            $targetTelegramUserId,
            $contentType,
            $caption,
            strtoupper($media->publicId),
            $media->detectedMime,
            $media->byteSize,
            strtolower($media->contentSha256),
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
        string $contentType,
        #[SensitiveParameter] string $caption,
        string $mediaPublicId,
        string $mediaDetectedMime,
        int $mediaByteSize,
        string $mediaContentSha256,
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
                    $contentType,
                    $caption,
                    $mediaPublicId,
                    $mediaDetectedMime,
                    $mediaByteSize,
                    $mediaContentSha256,
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
        string $contentType,
        #[SensitiveParameter] string $caption,
        string $mediaPublicId,
        string $mediaDetectedMime,
        int $mediaByteSize,
        string $mediaContentSha256,
        #[SensitiveParameter] string $key,
    ): string {
        return hash_hmac(
            'sha256',
            implode("\0", [
                'telegram-admin-direct-media-v1',
                $publicId,
                (string) $administratorId,
                $botId,
                $targetAccountPublicId,
                $targetTelegramUserId,
                $contentType,
                $caption,
                $mediaPublicId,
                $mediaDetectedMime,
                (string) $mediaByteSize,
                $mediaContentSha256,
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
            throw new RuntimeException('Telegram administrator direct-media integrity keyring is unavailable.');
        }

        $keys = [$currentKey];
        foreach ($previousKeys as $previousKey) {
            if (! is_string($previousKey) || strlen($previousKey) < 16) {
                throw new RuntimeException('Telegram administrator direct-media integrity keyring is unavailable.');
            }
            if (! in_array($previousKey, $keys, true)) {
                $keys[] = $previousKey;
            }
        }

        return $keys;
    }

    private function linkDeliveryOperation(string $publicId, int $administratorId, string $operationPublicId): void
    {
        $this->assertPublicId($publicId, 'Telegram administrator direct-media identity');
        $this->assertPublicId($operationPublicId, 'Telegram delivery operation identity');

        $this->database->connection()->transaction(function (Connection $connection) use (
            $publicId,
            $administratorId,
            $operationPublicId,
        ): void {
            $row = $this->rowByPublicId($connection, $publicId, true);
            if ($row === null || (int) $row->actor_administrator_id !== $administratorId) {
                throw new DomainException('Telegram administrator direct-media record is unavailable.');
            }
            if ($row->delivery_operation_public_id !== null) {
                if (! is_string($row->delivery_operation_public_id)
                    || ! hash_equals($row->delivery_operation_public_id, $operationPublicId)) {
                    throw new RuntimeException('Telegram administrator direct-media delivery linkage conflicts with an existing operation.');
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
                throw new RuntimeException('Telegram administrator direct-media delivery linkage was not persisted.');
            }
        }, 3);
    }

    private function filename(string $publicId, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            default => throw new DomainException('Telegram administrator direct-media MIME type is unsupported.'),
        };

        return 'admin-direct-'.strtolower($publicId).'.'.$extension;
    }

    private function draftTtlSeconds(): int
    {
        $ttl = filter_var(
            $this->config->get('telegram.interaction_session_ttl_seconds'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 86_400]],
        );
        if ($ttl === false) {
            throw new RuntimeException('Telegram administrator direct-media draft TTL is invalid.');
        }

        return (int) $ttl;
    }

    private function requestHash(string $requestKey): string
    {
        if ($requestKey === '' || strlen($requestKey) > 512) {
            throw new InvalidArgumentException('Telegram administrator direct-media request key is invalid.');
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
            throw new RuntimeException('Telegram administrator direct-media timestamp is invalid.');
        }
    }

    /** @return DirectMediaRow|null */
    private function rowByRequestHash(Connection $connection, string $requestHash, bool $lock): ?object
    {
        $query = $connection->table('telegram_administrator_direct_messages')
            ->where('create_request_hash', $requestHash);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DirectMediaRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return DirectMediaRow|null */
    private function rowByPublicId(Connection $connection, string $publicId, bool $lock): ?object
    {
        $this->assertPublicId($publicId, 'Telegram administrator direct-media identity');
        $query = $connection->table('telegram_administrator_direct_messages')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DirectMediaRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'id', 'public_id', 'create_request_hash', 'actor_administrator_id', 'bot_id',
            'target_account_public_id', 'target_telegram_user_id', 'content_type', 'content_ciphertext',
            'content_integrity_hash', 'content_length', 'media_public_id', 'media_detected_mime',
            'media_byte_size', 'media_content_sha256', 'correlation_id', 'delivery_operation_public_id',
            'expires_at', 'confirmed_at', 'queued_at', 'created_at', 'updated_at',
        ];
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === 1062 || (string) $exception->getCode() === '23000';
    }
}
