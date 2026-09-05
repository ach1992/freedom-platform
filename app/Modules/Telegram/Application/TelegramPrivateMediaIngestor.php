<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use stdClass;

final readonly class TelegramPrivateMediaIngestor
{
    private const REFERENCE_PREFIX = 'telegram-private-media:';

    private const DISK = 'telegram_private_media';

    private const MAX_PROVIDER_DOWNLOAD_BYTES = 20_000_000;

    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private FilesystemManager $filesystems,
        private TelegramPrivateMediaFetcher $fetcher,
        private Clock $clock,
    ) {}

    /** @requirement C2C-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-001 QUA-004 */
    public function ingest(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        int $userId,
        TelegramPrivateMediaInput $input,
    ): TelegramPrivateMediaReceipt {
        $this->assertIdentity($botId, $updateId, $telegramAccountId, $userId);
        $maximumBytes = $this->maximumBytes();
        if ($input->reportedFileSize !== null && $input->reportedFileSize > $maximumBytes) {
            throw new TelegramPrivateMediaRejected('file_too_large');
        }
        [$row, $replayed] = $this->reserve(
            $botId,
            $updateId,
            $telegramAccountId,
            $userId,
            $input,
        );
        if (in_array($row->state, ['stored', 'associated'], true)) {
            return $this->receipt($row, true);
        }
        if ($row->state === 'rejected' || $row->state === 'discarded') {
            $disk = $this->disk();
            $storagePath = (string) $row->storage_path;
            $disk->delete($storagePath);
            if ($disk->exists($storagePath)) {
                throw new RuntimeException('Telegram private-media terminal file cleanup failed.');
            }
            throw new TelegramPrivateMediaRejected((string) $row->rejection_code);
        }
        if ($row->state !== 'pending') {
            throw new RuntimeException('Telegram private-media persistence state is invalid.');
        }

        $disk = $this->disk();
        $storagePath = (string) $row->storage_path;
        if ($disk->exists($storagePath)) {
            $storedBytes = $disk->get($storagePath);
            try {
                [$mime, $hash, $size] = $this->validatedContent($storedBytes, $maximumBytes);

                return $this->finalizeStored($row, $mime, $hash, $size, true);
            } catch (TelegramPrivateMediaRejected) {
                $disk->delete($storagePath);
            }
        }

        try {
            $download = $this->fetcher->fetch($input->fileId, $input->fileUniqueId, $maximumBytes);
            $content = $download->bytes();
            [$mime, $hash, $size] = $this->validatedContent($content, $maximumBytes);
        } catch (TelegramPrivateMediaRejected $exception) {
            $this->reject($row, $exception->reasonCode);

            throw $exception;
        }

        if (! $disk->put($storagePath, $content)) {
            throw new RuntimeException('Telegram private-media storage write failed.');
        }
        $persisted = $disk->get($storagePath);
        if (! hash_equals($hash, hash('sha256', $persisted)) || strlen($persisted) !== $size) {
            $disk->delete($storagePath);

            throw new RuntimeException('Telegram private-media storage verification failed.');
        }

        return $this->finalizeStored($row, $mime, $hash, $size, $replayed);
    }

    public function associate(
        TelegramPrivateMediaReceipt $receipt,
        int $userId,
        string $associationType,
        string $associationPublicId,
    ): void {
        if ($userId < 1
            || $associationType !== 'c2c_manual_submission'
            || ! Str::isUlid($associationPublicId)) {
            throw new RuntimeException('Telegram private-media association identity is invalid.');
        }
        $normalizedAssociationPublicId = strtoupper($associationPublicId);

        $this->database->connection()->transaction(function (Connection $connection) use (
            $receipt,
            $userId,
            $associationType,
            $normalizedAssociationPublicId,
        ): void {
            /** @var stdClass|null $row */
            $row = $connection->table('telegram_private_media')
                ->where('public_id', strtoupper($receipt->publicId))
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw new RuntimeException('Telegram private-media association record is unavailable.');
            }
            if ($row->state === 'associated') {
                if ($row->association_type !== $associationType
                    || ! is_string($row->association_public_id)
                    || ! hash_equals(strtoupper($row->association_public_id), $normalizedAssociationPublicId)) {
                    throw new RuntimeException('Telegram private-media association conflicts with accepted evidence.');
                }

                return;
            }
            if ($row->state !== 'stored') {
                throw new RuntimeException('Telegram private-media cannot be associated from its current state.');
            }

            $updated = $connection->table('telegram_private_media')
                ->where('id', $row->id)
                ->where('state', 'stored')
                ->update([
                    'state' => 'associated',
                    'association_type' => $associationType,
                    'association_public_id' => $normalizedAssociationPublicId,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Telegram private-media association changed concurrently.');
            }
        }, 3);
    }

    public function discardIfUnassociated(TelegramPrivateMediaReceipt $receipt, int $userId): void
    {
        if ($userId < 1) {
            throw new RuntimeException('Telegram private-media discard actor is invalid.');
        }

        $storagePath = $this->database->connection()->transaction(function (Connection $connection) use ($receipt, $userId): ?string {
            /** @var stdClass|null $row */
            $row = $connection->table('telegram_private_media')
                ->where('public_id', strtoupper($receipt->publicId))
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            if ($row === null || $row->state === 'associated') {
                return null;
            }
            if ($row->state === 'discarded') {
                return (string) $row->storage_path;
            }
            if (! in_array($row->state, ['pending', 'stored', 'rejected'], true)) {
                throw new RuntimeException('Telegram private-media cannot be discarded from its current state.');
            }

            $updated = $connection->table('telegram_private_media')
                ->where('id', $row->id)
                ->whereIn('state', ['pending', 'stored', 'rejected'])
                ->update([
                    'state' => 'discarded',
                    'detected_mime' => null,
                    'byte_size' => null,
                    'content_sha256' => null,
                    'association_type' => null,
                    'association_public_id' => null,
                    'rejection_code' => 'discarded_unassociated',
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Telegram private-media discard state changed concurrently.');
            }

            return (string) $row->storage_path;
        }, 3);

        if ($storagePath === null) {
            return;
        }

        $disk = $this->disk();
        $disk->delete($storagePath);
        if ($disk->exists($storagePath)) {
            throw new RuntimeException('Telegram private-media discarded file cleanup failed.');
        }
    }

    /** @return array{0:stdClass,1:bool} */
    private function reserve(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        int $userId,
        TelegramPrivateMediaInput $input,
    ): array {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $botId,
            $updateId,
            $telegramAccountId,
            $userId,
            $input,
        ): array {
            /** @var stdClass|null $existing */
            $existing = $connection->table('telegram_private_media')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertReplayIdentity($existing, $telegramAccountId, $userId, $input);

                return [$existing, true];
            }

            $publicId = strtoupper((string) Str::ulid());
            $storagePath = $this->storagePath($publicId);
            $now = $this->timestamp();
            $connection->table('telegram_private_media')->insert([
                'public_id' => $publicId,
                'bot_id' => $botId,
                'update_id' => $updateId,
                'telegram_account_id' => $telegramAccountId,
                'user_id' => $userId,
                'source_kind' => $input->sourceKind,
                'encrypted_file_id' => $this->encrypter->encryptString($input->fileId->reveal()),
                'encrypted_file_unique_id' => $this->encrypter->encryptString($input->fileUniqueId->reveal()),
                'reported_file_size' => $input->reportedFileSize,
                'storage_path' => $storagePath,
                'state' => 'pending',
                'detected_mime' => null,
                'byte_size' => null,
                'content_sha256' => null,
                'association_type' => null,
                'association_public_id' => null,
                'rejection_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var stdClass|null $created */
            $created = $connection->table('telegram_private_media')
                ->where('bot_id', $botId)
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first();
            if ($created === null) {
                throw new RuntimeException('Telegram private-media reservation persistence failed.');
            }

            return [$created, false];
        }, 3);
    }

    private function assertReplayIdentity(
        stdClass $row,
        int $telegramAccountId,
        int $userId,
        TelegramPrivateMediaInput $input,
    ): void {
        $storedFileId = $this->encrypter->decryptString((string) $row->encrypted_file_id);
        $storedUniqueId = $this->encrypter->decryptString((string) $row->encrypted_file_unique_id);
        $reportedSize = $row->reported_file_size === null ? null : (int) $row->reported_file_size;
        if ((int) $row->telegram_account_id !== $telegramAccountId
            || (int) $row->user_id !== $userId
            || $row->source_kind !== $input->sourceKind
            || ! hash_equals($storedFileId, $input->fileId->reveal())
            || ! hash_equals($storedUniqueId, $input->fileUniqueId->reveal())
            || $reportedSize !== $input->reportedFileSize) {
            throw new RuntimeException('Telegram private-media update identity conflicts with accepted input.');
        }
    }

    private function finalizeStored(
        stdClass $row,
        string $mime,
        string $hash,
        int $size,
        bool $replayed,
    ): TelegramPrivateMediaReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $row,
            $mime,
            $hash,
            $size,
            $replayed,
        ): TelegramPrivateMediaReceipt {
            /** @var stdClass|null $current */
            $current = $connection->table('telegram_private_media')->where('id', $row->id)->lockForUpdate()->first();
            if ($current === null) {
                throw new RuntimeException('Telegram private-media persistence disappeared.');
            }
            if (in_array($current->state, ['stored', 'associated'], true)) {
                $stored = $this->receipt($current, true);
                if (! hash_equals($stored->contentSha256, $hash)
                    || $stored->detectedMime !== $mime
                    || $stored->byteSize !== $size) {
                    throw new RuntimeException('Telegram private-media stored replay conflicts with downloaded content.');
                }

                return $stored;
            }
            if ($current->state !== 'pending') {
                throw new RuntimeException('Telegram private-media cannot be finalized from its current state.');
            }

            $updated = $connection->table('telegram_private_media')
                ->where('id', $current->id)
                ->where('state', 'pending')
                ->update([
                    'state' => 'stored',
                    'detected_mime' => $mime,
                    'byte_size' => $size,
                    'content_sha256' => $hash,
                    'rejection_code' => null,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Telegram private-media finalization changed concurrently.');
            }

            /** @var stdClass|null $stored */
            $stored = $connection->table('telegram_private_media')->where('id', $current->id)->first();
            if ($stored === null) {
                throw new RuntimeException('Telegram private-media final persistence failed.');
            }

            return $this->receipt($stored, $replayed);
        }, 3);
    }

    private function reject(stdClass $row, string $reasonCode): void
    {
        $this->disk()->delete((string) $row->storage_path);
        $this->database->connection()->transaction(function (Connection $connection) use ($row, $reasonCode): void {
            /** @var stdClass|null $current */
            $current = $connection->table('telegram_private_media')->where('id', $row->id)->lockForUpdate()->first();
            if ($current === null) {
                throw new RuntimeException('Telegram private-media rejection persistence disappeared.');
            }
            if ($current->state === 'rejected' && hash_equals((string) $current->rejection_code, $reasonCode)) {
                return;
            }
            if ($current->state !== 'pending') {
                throw new RuntimeException('Telegram private-media cannot be rejected from its current state.');
            }

            $updated = $connection->table('telegram_private_media')
                ->where('id', $current->id)
                ->where('state', 'pending')
                ->update([
                    'state' => 'rejected',
                    'detected_mime' => null,
                    'byte_size' => null,
                    'content_sha256' => null,
                    'association_type' => null,
                    'association_public_id' => null,
                    'rejection_code' => $reasonCode,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Telegram private-media rejection changed concurrently.');
            }
        }, 3);
    }

    /** @return array{0:string,1:string,2:int} */
    private function validatedContent(#[SensitiveParameter] string $content, int $maximumBytes): array
    {
        $size = strlen($content);
        if ($size < 1) {
            throw new TelegramPrivateMediaRejected('empty_file');
        }
        if ($size > $maximumBytes) {
            throw new TelegramPrivateMediaRejected('file_too_large');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);
        if (! is_string($mime) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new TelegramPrivateMediaRejected('unsupported_image_type');
        }

        $image = @getimagesizefromstring($content);
        if (! is_array($image)
            || $image[0] < 1
            || $image[1] < 1
            || $image[0] > 50_000
            || $image[1] > 50_000
            || image_type_to_mime_type($image[2]) !== $mime
            || TelegramImagePayloadIntegrity::inspect($content) !== TelegramImagePayloadIntegrity::COMPLETE) {
            throw new TelegramPrivateMediaRejected('malformed_image');
        }

        return [$mime, hash('sha256', $content), $size];
    }

    private function receipt(stdClass $row, bool $replayed): TelegramPrivateMediaReceipt
    {
        if (! in_array($row->state, ['stored', 'associated'], true)
            || ! is_string($row->detected_mime)
            || ! is_string($row->content_sha256)
            || $row->byte_size === null) {
            throw new RuntimeException('Telegram private-media stored receipt is incomplete.');
        }
        $publicId = strtoupper((string) $row->public_id);

        return new TelegramPrivateMediaReceipt(
            $publicId,
            self::REFERENCE_PREFIX.$publicId,
            strtolower($row->content_sha256),
            $row->detected_mime,
            (int) $row->byte_size,
            $replayed,
        );
    }

    private function disk(): FilesystemAdapter
    {
        $disk = $this->filesystems->disk(self::DISK);
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Telegram private-media filesystem is unavailable.');
        }

        return $disk;
    }

    private function maximumBytes(): int
    {
        $value = config('telegram.private_media_max_bytes');
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => self::MAX_PROVIDER_DOWNLOAD_BYTES]],
        );
        if ($validated === false) {
            throw new RuntimeException('Telegram private-media size limit is invalid.');
        }

        return (int) $validated;
    }

    private function storagePath(string $publicId): string
    {
        $prefix = substr(hash('sha256', $publicId), 0, 2);

        return 'receipts/'.$prefix.'/'.$publicId.'.media';
    }

    private function assertIdentity(string $botId, int $updateId, int $telegramAccountId, int $userId): void
    {
        if (preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1
            || $updateId < 0
            || $telegramAccountId < 1
            || $userId < 1) {
            throw new RuntimeException('Telegram private-media ingestion identity is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
