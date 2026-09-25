<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\RestrictedValue;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class TelegramPrivateMediaDeliveryResolver
{
    private const REFERENCE_PREFIX = 'telegram-private-media:';

    private const DISK = 'telegram_private_media';

    public function __construct(
        private DatabaseManager $database,
        private FilesystemManager $filesystems,
    ) {}

    /** @requirement SUP-001 SUP-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 */
    public function resolveSupportAttachment(
        RestrictedValue $privateReference,
        string $attachmentPublicId,
    ): TelegramPrivateMediaDeliveryPayload {
        if (! Str::isUlid($attachmentPublicId)) {
            throw new RuntimeException('Support attachment delivery identity is invalid.');
        }

        return $this->resolveAssociatedReference(
            $privateReference,
            'support_ticket_attachment',
            strtoupper($attachmentPublicId),
            'Support attachment',
        );
    }

    /** @requirement C2C-002 GFT-002 USDT-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 */
    public function resolvePaymentReviewEvidence(
        RestrictedValue $privateReference,
        string $associationType,
        string $associationPublicId,
    ): TelegramPrivateMediaDeliveryPayload {
        TelegramPrivateMediaDeliveryProvenanceGuard::assertPaymentReviewProtectedResolverCaller();
        if (! in_array($associationType, [
            'c2c_manual_submission',
            'gift_card_submission',
            'usdt_txid_submission',
        ], true)
            || ! Str::isUlid($associationPublicId)) {
            throw new RuntimeException('Payment-review private-media association identity is invalid.');
        }

        return $this->resolveAssociatedReference(
            $privateReference,
            $associationType,
            strtoupper($associationPublicId),
            'Payment-review evidence',
        );
    }

    /** @requirement COM-001 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 */
    public function resolveAdministratorDirectMessage(
        string $mediaPublicId,
        string $directMessagePublicId,
    ): TelegramPrivateMediaDeliveryPayload {
        TelegramPrivateMediaDeliveryProvenanceGuard::assertDirectMediaAuthorityCaller();

        if (! Str::isUlid($mediaPublicId) || ! Str::isUlid($directMessagePublicId)) {
            throw new DomainException('Administrator direct-message private-media identity is invalid.');
        }
        $mediaPublicId = strtoupper($mediaPublicId);
        $directMessagePublicId = strtoupper($directMessagePublicId);

        /** @var stdClass|null $row */
        $row = $this->database->connection()->table('telegram_private_media')
            ->where('public_id', $mediaPublicId)
            ->where('state', 'associated')
            ->where('association_type', 'administrator_direct_message')
            ->where('association_public_id', $directMessagePublicId)
            ->first(['public_id', 'storage_path', 'detected_mime', 'byte_size', 'content_sha256']);
        if ($row === null
            || ! is_string($row->storage_path)
            || ! is_string($row->detected_mime)
            || ! is_string($row->content_sha256)
            || $row->byte_size === null) {
            throw new DomainException('Administrator direct-message private media is unavailable.');
        }

        $expectedPath = $this->storagePath($mediaPublicId);
        if (! hash_equals($expectedPath, $row->storage_path)) {
            throw new DomainException('Administrator direct-message private-media storage identity is invalid.');
        }

        $disk = $this->disk();
        if (! $disk->exists($expectedPath)) {
            throw new RuntimeException('Administrator direct-message private-media bytes are unavailable.');
        }
        $contents = $disk->get($expectedPath);
        [$mime, $hash, $size] = TelegramPrivateMediaContentValidator::validate($contents, 20_000_000);
        if ($mime !== $row->detected_mime
            || $size !== (int) $row->byte_size
            || ! hash_equals(strtolower((string) $row->content_sha256), $hash)) {
            throw new DomainException('Administrator direct-message private-media bytes failed integrity verification.');
        }

        return new TelegramPrivateMediaDeliveryPayload($contents, $mime, $size);
    }

    private function resolveAssociatedReference(
        RestrictedValue $privateReference,
        string $associationType,
        string $associationPublicId,
        string $label,
    ): TelegramPrivateMediaDeliveryPayload {
        $reference = $privateReference->reveal();
        if (! str_starts_with($reference, self::REFERENCE_PREFIX)) {
            throw new RuntimeException($label.' private-media reference is invalid.');
        }
        $mediaPublicId = strtoupper(substr($reference, strlen(self::REFERENCE_PREFIX)));
        if (! Str::isUlid($mediaPublicId)) {
            throw new RuntimeException($label.' private-media reference is invalid.');
        }

        /** @var stdClass|null $row */
        $row = $this->database->connection()->table('telegram_private_media')
            ->where('public_id', $mediaPublicId)
            ->where('state', 'associated')
            ->where('association_type', $associationType)
            ->where('association_public_id', $associationPublicId)
            ->first(['public_id', 'storage_path', 'detected_mime', 'byte_size', 'content_sha256']);
        if ($row === null
            || ! is_string($row->storage_path)
            || ! is_string($row->detected_mime)
            || ! is_string($row->content_sha256)
            || $row->byte_size === null) {
            throw new RuntimeException($label.' private media is unavailable.');
        }

        $expectedPath = $this->storagePath($mediaPublicId);
        if (! hash_equals($expectedPath, $row->storage_path)) {
            throw new RuntimeException($label.' private-media storage identity is invalid.');
        }

        $disk = $this->disk();
        if (! $disk->exists($expectedPath)) {
            throw new RuntimeException($label.' private-media bytes are unavailable.');
        }
        $contents = $disk->get($expectedPath);
        [$mime, $hash, $size] = TelegramPrivateMediaContentValidator::validate($contents, 20_000_000);
        if ($mime !== $row->detected_mime
            || $size !== (int) $row->byte_size
            || ! hash_equals(strtolower((string) $row->content_sha256), $hash)) {
            throw new RuntimeException($label.' private-media bytes failed integrity verification.');
        }

        return new TelegramPrivateMediaDeliveryPayload($contents, $mime, $size);
    }

    private function storagePath(string $publicId): string
    {
        return 'receipts/'.substr(hash('sha256', $publicId), 0, 2).'/'.$publicId.'.media';
    }

    private function disk(): FilesystemAdapter
    {
        $disk = $this->filesystems->disk(self::DISK);
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Telegram private-media filesystem is unavailable.');
        }

        return $disk;
    }
}
