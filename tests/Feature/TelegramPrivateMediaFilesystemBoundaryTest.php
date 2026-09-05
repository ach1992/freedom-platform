<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement DAT-003 SEC-003 SEC-009 QUA-001 */
final class TelegramPrivateMediaFilesystemBoundaryTest extends TestCase
{
    public function test_served_local_disk_cannot_address_restricted_receipt_bytes(): void
    {
        [$receiptPath, $localAliasPath] = $this->paths();
        $content = 'restricted-receipt-boundary-fixture';
        $private = Storage::disk('telegram_private_media');
        $local = Storage::disk('local');

        try {
            self::assertTrue($private->put($receiptPath, $content));
            self::assertSame($content, $private->get($receiptPath));
            $absolutePath = $private->path($receiptPath);
            self::assertSame(0600, fileperms($absolutePath) & 0777);
            self::assertSame(0700, fileperms(dirname($absolutePath)) & 0777);
            self::assertFalse(
                $local->exists($localAliasPath),
                'The served local disk must not resolve the Telegram private-media tree.',
            );
        } finally {
            $private->delete($receiptPath);
            $local->delete($localAliasPath);
        }
    }

    public function test_signed_local_get_cannot_serve_restricted_receipt_bytes(): void
    {
        [$receiptPath, $localAliasPath] = $this->paths();
        $content = 'restricted-receipt-signed-get-fixture';
        $private = Storage::disk('telegram_private_media');
        $local = Storage::disk('local');

        try {
            self::assertTrue($private->put($receiptPath, $content));

            $this->get($local->temporaryUrl($localAliasPath, now()->addMinute()))
                ->assertNotFound();

            self::assertSame($content, $private->get($receiptPath));
        } finally {
            $private->delete($receiptPath);
            $local->delete($localAliasPath);
        }
    }

    public function test_signed_local_upload_cannot_modify_restricted_receipt_bytes(): void
    {
        [$receiptPath, $localAliasPath] = $this->paths();
        $content = 'restricted-receipt-signed-put-fixture';
        $private = Storage::disk('telegram_private_media');
        $local = Storage::disk('local');

        try {
            self::assertTrue($private->put($receiptPath, $content));
            $upload = $local->temporaryUploadUrl($localAliasPath, now()->addMinute());

            $this->call('PUT', $upload['url'], [], [], [], [], 'generic-local-upload')
                ->assertNoContent();

            self::assertSame(
                $content,
                $private->get($receiptPath),
                'A signed upload through the served local disk must not overwrite Telegram receipt evidence.',
            );
            self::assertSame('generic-local-upload', $local->get($localAliasPath));
        } finally {
            $private->delete($receiptPath);
            $local->delete($localAliasPath);
        }
    }

    public function test_private_media_disk_remains_non_served_private_and_outside_public_links(): void
    {
        $privateRoot = rtrim((string) config('filesystems.disks.telegram_private_media.root'), DIRECTORY_SEPARATOR);

        self::assertFalse((bool) config('filesystems.disks.telegram_private_media.serve'));
        self::assertFalse(Route::has('storage.telegram_private_media'));
        self::assertFalse(Route::has('storage.telegram_private_media.upload'));

        foreach ((array) config('filesystems.disks') as $name => $disk) {
            if (! is_array($disk)
                || ($disk['driver'] ?? null) !== 'local'
                || ! ($disk['serve'] ?? false)) {
                continue;
            }

            $servedRoot = rtrim((string) ($disk['root'] ?? ''), DIRECTORY_SEPARATOR);
            self::assertNotSame('', $servedRoot, "Served local disk [{$name}] must declare an explicit root.");
            self::assertFalse(
                str_starts_with($privateRoot.DIRECTORY_SEPARATOR, $servedRoot.DIRECTORY_SEPARATOR),
                "Telegram private media must not be nested below served local disk [{$name}].",
            );
        }
        self::assertSame('private', config('filesystems.disks.telegram_private_media.visibility'));
        self::assertSame('private', config('filesystems.disks.telegram_private_media.directory_visibility'));
        self::assertSame(0600, config('filesystems.disks.telegram_private_media.permissions.file.private'));
        self::assertSame(0700, config('filesystems.disks.telegram_private_media.permissions.dir.private'));
        self::assertSame(
            storage_path('app/public'),
            config('filesystems.links.'.public_path('storage')),
        );
    }

    /** @return array{0:string,1:string} */
    private function paths(): array
    {
        $publicId = strtoupper((string) Str::ulid());
        $prefix = substr(hash('sha256', $publicId), 0, 2);
        $receiptPath = 'receipts/'.$prefix.'/'.$publicId.'.media';

        return [$receiptPath, 'telegram-private-media/'.$receiptPath];
    }
}
