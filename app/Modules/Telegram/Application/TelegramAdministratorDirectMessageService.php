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
 *     correlation_id:string,
 *     delivery_operation_public_id:string|null,
 *     expires_at:string,
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
        $normalizedText = $this->normalizedText($text);
        $requestHash = $this->requestHash($requestKey);
        $connection = $this->database->connection();

        try {
            return $connection->transaction(function (Connection $connection) use (
                $administratorId,
                $botId,
                $target,
                $normalizedText,
                $requestHash,
            ): TelegramAdministratorDirectMessageDraft {
                $existing = $this->rowByRequestHash($connection, $requestHash, true);
                if ($existing !== null) {
                    return $this->draftFromRow(
                        $existing,
                        $administratorId,
                        $botId,
                        $target,
                        $normalizedText,
                        true,
                    );
                }

                $now = $this->clock->now();
                $publicId = (string) Str::ulid();
                $correlationId = 'tg-admin-dm:'.substr(hash('sha256', $publicId), 0, 40);
                $ciphertext = $this->encrypt($normalizedText);
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
                        $normalizedText,
                    ),
                    'content_length' => mb_strlen($normalizedText),
                    'correlation_id' => $correlationId,
                    'delivery_operation_public_id' => null,
                    'expires_at' => $expiresAt,
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
                    $normalizedText,
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
                $normalizedText,
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
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct-message draft is unavailable.');
        }

        return $this->draftFromRow($row, $administratorId, $botId, $target, null, false);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 OPS-003 QUA-001 QUA-004 */
    public function confirmText(
        int $actorUserId,
        string $botId,
        string $selectionToken,
        string $publicId,
    ): TelegramDeliveryOperationReceipt {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $target = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $row = $this->rowByPublicId($this->database->connection(), $publicId, false);
        if ($row === null) {
            throw new DomainException('Telegram administrator direct-message draft is unavailable.');
        }
        $draft = $this->draftFromRow($row, $administratorId, $botId, $target, null, false);

        // Re-authorize and re-resolve at the immediate external-effect boundary.
        $recheckedAdministratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        if ($recheckedAdministratorId !== $administratorId) {
            throw new RuntimeException('Telegram administrator direct-message actor changed during confirmation.');
        }
        $currentTarget = $this->targets->resolve($actorUserId, $botId, $selectionToken);
        $this->assertTargetMatches($currentTarget, $draft->targetAccountPublicId, $draft->targetTelegramUserId);

        $recipientChatId = filter_var(
            $currentTarget->telegramUserId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($recipientChatId === false) {
            throw new DomainException('Telegram administrator direct-message target is not addressable.');
        }

        $source = new readonly class($draft->text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(#[SensitiveParameter] private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };
        $presentation = $this->presentations->fromSource($source);
        $receipt = $this->delivery->send(
            $recipientChatId,
            $presentation,
            'tg-admin-direct-message-send:'.$publicId,
            $draft->correlationId,
        );

        $this->linkDeliveryOperation($publicId, $administratorId, $receipt->publicId);

        return $receipt;
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
        $publicId = (string) $row->public_id;
        $this->assertPublicId($publicId, 'Telegram administrator direct-message identity');
        if ((int) $row->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $row->bot_id, $botId)
            || ! hash_equals((string) $row->target_account_public_id, $target->accountPublicId)
            || ! hash_equals((string) $row->target_telegram_user_id, $target->telegramUserId)
            || (string) $row->content_type !== self::CONTENT_TYPE_TEXT) {
            throw new DomainException('Telegram administrator direct-message draft does not match the current actor or target.');
        }
        if ($row->delivery_operation_public_id === null
            && $this->parseTime((string) $row->expires_at) <= $this->clock->now()) {
            throw new DomainException('Telegram administrator direct-message draft has expired.');
        }

        $text = $this->decrypt((string) $row->content_ciphertext);
        if ((int) $row->content_length !== mb_strlen($text)
            || ! hash_equals(
                (string) $row->content_integrity_hash,
                $this->integrityHash(
                    $publicId,
                    $administratorId,
                    $botId,
                    $target->accountPublicId,
                    $target->telegramUserId,
                    $text,
                ),
            )) {
            throw new DomainException('Telegram administrator direct-message content integrity validation failed.');
        }
        if ($expectedText !== null && ! hash_equals($expectedText, $text)) {
            throw new RuntimeException('Telegram administrator direct-message idempotency key conflicts with different content.');
        }

        return new TelegramAdministratorDirectMessageDraft(
            $publicId,
            $target->accountPublicId,
            $target->telegramUserId,
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

    private function normalizedText(#[SensitiveParameter] string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new DomainException('Telegram administrator direct-message text must be valid UTF-8.');
        }
        $normalized = trim($text);
        if ($normalized === '' || mb_strlen($normalized) > self::MAX_TEXT_LENGTH) {
            throw new DomainException('Telegram administrator direct-message text length is invalid.');
        }

        return $normalized;
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
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
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
            $this->integrityKey(),
        );
    }

    private function integrityKey(): string
    {
        $key = $this->config->get('app.key');
        if (! is_string($key) || strlen($key) < 16) {
            throw new RuntimeException('Telegram administrator direct-message integrity key is unavailable.');
        }

        return $key;
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
            'content_integrity_hash', 'content_length', 'correlation_id', 'delivery_operation_public_id',
            'expires_at', 'queued_at', 'created_at', 'updated_at',
        ];
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === 1062 || (string) $exception->getCode() === '23000';
    }
}
