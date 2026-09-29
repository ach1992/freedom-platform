<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Telegram\Application\Contracts\TelegramBackupArtifactPresentationSource;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use DomainException;
use Illuminate\Database\DatabaseManager;
use JsonException;
use RuntimeException;

final readonly class BackupTelegramArtifactPresentationSource implements TelegramBackupArtifactPresentationSource
{
    private const AUTHORITY = 'freedom_platform_backup_telegram_v1';

    public function __construct(
        private DatabaseManager $database,
        private BackupRepository $repository,
    ) {}

    /** @requirement BAK-001 SEC-001 SEC-002 OPS-003 QUA-001 */
    public function resolveForOwner(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation {
        $this->assertCurrentOwner($userId);

        if (! $reference->isBackupExport()) {
            throw new DomainException('Protected Telegram backup-export reference is unavailable.');
        }

        $backupId = $reference->publicId;
        $identity = $reference->backupExportIdentity();
        $manifestJson = $this->repository->telegramExportManifest($backupId);

        try {
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Backup Telegram export manifest is invalid.', previous: $exception);
        }

        if (! is_array($manifest)
            || ($manifest['authority'] ?? null) !== self::AUTHORITY
            || ($manifest['backup_id'] ?? null) !== $backupId
            || ! is_int($manifest['part_count'] ?? null)
            || $manifest['part_count'] !== $identity['count']
            || ! is_array($manifest['parts'] ?? null)
            || count($manifest['parts']) !== $identity['count']
        ) {
            throw new RuntimeException('Backup Telegram export manifest identity changed.');
        }

        if ($identity['item'] === 'manifest') {
            $this->assertExpectedContent(
                $manifestJson,
                $identity['bytes'],
                $identity['sha256'],
                'Backup Telegram export manifest',
            );

            return ProtectedTelegramPresentation::binaryDocument(
                $manifestJson,
                'backup-'.$backupId.'.telegram-export.json',
                'Freedom Platform backup '.$backupId.' export manifest',
            );
        }

        $part = $manifest['parts'][$identity['index'] - 1] ?? null;
        if (! is_array($part)
            || ($part['index'] ?? null) !== $identity['index']
            || ! is_int($part['offset'] ?? null)
            || $part['offset'] < 0
            || ($part['bytes'] ?? null) !== $identity['bytes']
            || ($part['sha256'] ?? null) !== $identity['sha256']
            || ! is_string($part['filename'] ?? null)
            || preg_match(
                '/\Abackup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\.part-[0-9]{6}-of-[0-9]{6}\.fbk\z/',
                $part['filename'],
            ) !== 1
        ) {
            throw new RuntimeException('Backup Telegram export part identity changed.');
        }

        $bytes = $this->repository->readArtifactSlice(
            $backupId,
            $part['offset'],
            $identity['bytes'],
        );
        $this->assertExpectedContent(
            $bytes,
            $identity['bytes'],
            $identity['sha256'],
            'Backup Telegram export part',
        );

        return ProtectedTelegramPresentation::binaryDocument(
            $bytes,
            $part['filename'],
            sprintf(
                'Freedom Platform backup %s part %d/%d',
                $backupId,
                $identity['index'],
                $identity['count'],
            ),
        );
    }

    private function assertCurrentOwner(int $userId): void
    {
        if ($userId < 1) {
            throw new DomainException('Backup Telegram export is restricted to the current active Owner.');
        }

        $owners = $this->database->connection()
            ->table('administrators')
            ->where('is_owner', true)
            ->get(['user_id', 'status'])
            ->all();

        if (count($owners) !== 1
            || (int) ($owners[0]->user_id ?? 0) !== $userId
            || ($owners[0]->status ?? null) !== 'active'
        ) {
            throw new DomainException('Backup Telegram export is restricted to the current active Owner.');
        }
    }

    private function assertExpectedContent(
        string $contents,
        int $expectedBytes,
        string $expectedSha256,
        string $label,
    ): void {
        if (strlen($contents) !== $expectedBytes
            || ! hash_equals($expectedSha256, hash('sha256', $contents))
        ) {
            throw new RuntimeException($label.' failed integrity verification.');
        }
    }
}
