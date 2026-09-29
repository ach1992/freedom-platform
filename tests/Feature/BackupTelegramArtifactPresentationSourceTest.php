<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\BackupTelegramArtifactPresentationSource;
use App\Modules\Operations\Infrastructure\FilesystemBackupRepository;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\DatabaseBackupTelegramOwnerDestinationResolver;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final readonly class BackupTelegramPresentationTestRuntime implements TelegramDeliveryRuntime
{
    public function botId(): string
    {
        return '123456';
    }
}

/** @requirement BAK-001 SEC-001 SEC-002 OPS-003 QUA-001 */
final class BackupTelegramArtifactPresentationSourceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_current_owner_can_resolve_integrity_bound_manifest_and_part(): void
    {
        $base = $this->directory('owner');
        $repository = new FilesystemBackupRepository($base.'/backups');
        $backupId = '20260929T051000Z-2222222222222222';
        $artifact = '0123456789ABCDEFGHIJklmnop';
        $partBytes = 10;
        $this->completedBackupWithExportManifest($repository, $backupId, $artifact, $partBytes);
        $ownerUserId = $this->administrator(true, 900001);
        $source = new BackupTelegramArtifactPresentationSource(
            $this->app->make(DatabaseManager::class),
            $repository,
        );

        try {
            $manifestJson = $repository->telegramExportManifest($backupId);
            $manifestReference = TelegramProtectedPresentationReference::backupExport(
                $backupId,
                'manifest',
                0,
                3,
                strlen($manifestJson),
                hash('sha256', $manifestJson),
            );
            $manifestPresentation = $source->resolveForOwner($ownerUserId, $manifestReference);
            self::assertSame($manifestJson, $manifestPresentation->documentContents());
            self::assertSame('backup-'.$backupId.'.telegram-export.json', $manifestPresentation->documentFilename());

            $part = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR)['parts'][1];
            $partReference = TelegramProtectedPresentationReference::backupExport(
                $backupId,
                'part',
                2,
                3,
                $part['bytes'],
                $part['sha256'],
            );
            $partPresentation = $source->resolveForOwner($ownerUserId, $partReference);
            self::assertSame(substr($artifact, 10, 10), $partPresentation->documentContents());
            self::assertSame($part['filename'], $partPresentation->documentFilename());

            $destination = (new DatabaseBackupTelegramOwnerDestinationResolver(
                $this->app->make(DatabaseManager::class),
                new BackupTelegramPresentationTestRuntime,
            ))->resolve();
            self::assertSame($ownerUserId, $destination['user_id']);
            self::assertSame(900001, $destination['recipient_chat_id']);
        } finally {
            $this->removeTree($base);
        }
    }

    public function test_non_owner_and_tampered_part_are_rejected_before_provider_presentation(): void
    {
        $base = $this->directory('rejected');
        $repository = new FilesystemBackupRepository($base.'/backups');
        $backupId = '20260929T051100Z-3333333333333333';
        $artifact = 'abcdefghijABCDEFGHIJ12345';
        $this->completedBackupWithExportManifest($repository, $backupId, $artifact, 10);
        $ownerUserId = $this->administrator(true, 900001);
        $nonOwnerUserId = $this->administrator(false, 900002);
        $source = new BackupTelegramArtifactPresentationSource(
            $this->app->make(DatabaseManager::class),
            $repository,
        );
        $manifest = json_decode($repository->telegramExportManifest($backupId), true, flags: JSON_THROW_ON_ERROR);
        $part = $manifest['parts'][0];
        $reference = TelegramProtectedPresentationReference::backupExport(
            $backupId,
            'part',
            1,
            $manifest['part_count'],
            $part['bytes'],
            $part['sha256'],
        );

        try {
            try {
                $source->resolveForOwner($nonOwnerUserId, $reference);
                self::fail('A non-Owner must not resolve backup export bytes.');
            } catch (DomainException $exception) {
                self::assertSame(
                    'Backup Telegram export is restricted to the current active Owner.',
                    $exception->getMessage(),
                );
            }

            $artifactPath = $repository->root().'/completed/backup-'.$backupId.'.fbk';
            $tampered = $artifact;
            $tampered[0] = 'Z';
            file_put_contents($artifactPath, $tampered);

            try {
                $source->resolveForOwner($ownerUserId, $reference);
                self::fail('A tampered backup part must not reach a provider presentation.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Backup Telegram export part failed integrity verification.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $this->removeTree($base);
        }
    }

    private function administrator(bool $owner, int $telegramUserId): int
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('administrators')->insert([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => 123456,
            'telegram_user_id' => $telegramUserId,
            'username' => $owner ? 'backup_owner' : 'backup_non_owner',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function completedBackupWithExportManifest(
        FilesystemBackupRepository $repository,
        string $backupId,
        string $artifactContents,
        int $partBytes,
    ): void {
        $root = $repository->root();
        $filename = 'backup-'.$backupId.'.fbk';
        file_put_contents($root.'/completed/'.$filename, $artifactContents);
        file_put_contents(
            $root.'/completed/backup-'.$backupId.'.manifest.json',
            json_encode([
                'version' => 1,
                'authority' => 'freedom_platform_backup_v1',
                'backup_id' => $backupId,
                'completed_at' => '2026-09-29T05:10:00+00:00',
                'artifact' => [
                    'filename' => $filename,
                    'bytes' => strlen($artifactContents),
                    'sha256' => hash('sha256', $artifactContents),
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $partCount = intdiv(strlen($artifactContents) + $partBytes - 1, $partBytes);
        $parts = [];
        for ($index = 1; $index <= $partCount; $index++) {
            $offset = ($index - 1) * $partBytes;
            $bytes = substr($artifactContents, $offset, $partBytes);
            $parts[] = [
                'index' => $index,
                'offset' => $offset,
                'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'filename' => sprintf(
                    'backup-%s.part-%06d-of-%06d.fbk',
                    $backupId,
                    $index,
                    $partCount,
                ),
            ];
        }

        $repository->storeTelegramExportManifest(
            $backupId,
            json_encode([
                'version' => 1,
                'authority' => 'freedom_platform_backup_telegram_v1',
                'backup_id' => $backupId,
                'completed_at' => '2026-09-29T05:10:00+00:00',
                'artifact' => [
                    'filename' => $filename,
                    'bytes' => strlen($artifactContents),
                    'sha256' => hash('sha256', $artifactContents),
                ],
                'part_bytes' => $partBytes,
                'part_count' => $partCount,
                'parts' => $parts,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/backup-presentation-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$name);
        }
        @rmdir($path);
    }
}
