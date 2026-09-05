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
    public function test_existing_generic_local_files_keep_their_baseline_disk_path(): void
    {
        $relativePath = 'panel-cas/f006-'.bin2hex(random_bytes(6)).'.pem';
        $baselinePath = storage_path('app/private/'.$relativePath);

        try {
            if (! is_dir(dirname($baselinePath)) && ! mkdir(dirname($baselinePath), 0700, true) && ! is_dir(dirname($baselinePath))) {
                self::fail('Unable to create the baseline local-disk fixture directory.');
            }
            self::assertNotFalse(file_put_contents($baselinePath, 'legacy-local-custom-ca-fixture'));

            $local = Storage::disk('local');
            self::assertSame($baselinePath, $local->path($relativePath));
            self::assertTrue($local->exists($relativePath));
            self::assertSame('legacy-local-custom-ca-fixture', $local->get($relativePath));
        } finally {
            @unlink($baselinePath);
            @rmdir(dirname($baselinePath));
        }
    }

    public function test_generic_local_disk_preserves_baseline_root_without_http_serving(): void
    {
        self::assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        self::assertFalse((bool) config('filesystems.disks.local.serve'));
        self::assertFalse(Route::has('storage.local'));
        self::assertFalse(Route::has('storage.local.upload'));
        self::assertFalse(Storage::disk('local')->providesTemporaryUrls());
        self::assertFalse(Storage::disk('local')->providesTemporaryUploadUrls());
    }

    public function test_http_storage_routes_cannot_read_or_overwrite_restricted_receipt_bytes(): void
    {
        [$receiptPath, $formerLocalAliasPath] = $this->paths();
        $content = 'restricted-receipt-http-boundary-fixture';
        $private = Storage::disk('telegram_private_media');

        try {
            self::assertTrue($private->put($receiptPath, $content));
            self::assertSame($content, $private->get($receiptPath));

            $this->get('/storage/'.$formerLocalAliasPath)->assertNotFound();
            $this->call('PUT', '/storage/'.$formerLocalAliasPath, [], [], [], [], 'generic-local-upload')->assertNotFound();

            self::assertSame(
                $content,
                $private->get($receiptPath),
                'HTTP storage routes must not read or overwrite Telegram receipt evidence.',
            );
        } finally {
            $private->delete($receiptPath);
        }
    }

    public function test_restricted_receipt_permissions_remain_private(): void
    {
        [$receiptPath] = $this->paths();
        $private = Storage::disk('telegram_private_media');

        try {
            self::assertTrue($private->put($receiptPath, 'restricted-receipt-permission-fixture'));
            $absolutePath = $private->path($receiptPath);
            self::assertSame(0600, fileperms($absolutePath) & 0777);
            self::assertSame(0700, fileperms(dirname($absolutePath)) & 0777);
        } finally {
            $private->delete($receiptPath);
        }
    }

    public function test_private_media_disk_remains_non_served_private_and_outside_every_served_local_root(): void
    {
        $privateRoot = rtrim((string) config('filesystems.disks.telegram_private_media.root'), DIRECTORY_SEPARATOR);

        self::assertSame(storage_path('app/private/telegram-private-media'), $privateRoot);
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
