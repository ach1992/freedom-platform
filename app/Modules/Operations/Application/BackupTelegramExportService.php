<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Contracts\BackupRepository;
use App\Modules\Operations\Application\Contracts\BackupTelegramDeliveryQueue;
use App\Modules\Operations\Application\Contracts\BackupTelegramOwnerDestinationResolver;
use RuntimeException;

final readonly class BackupTelegramExportService
{
    private const AUTHORITY = 'freedom_platform_backup_telegram_v1';

    private const MAX_PARTS = 10_000;

    public function __construct(
        private BackupRuntimeConfiguration $configuration,
        private BackupRepository $repository,
        private BackupTelegramOwnerDestinationResolver $destination,
        private BackupTelegramDeliveryQueue $delivery,
    ) {}

    /** @requirement BAK-001 SEC-001 SEC-002 OPS-003 QUA-001 */
    public function queue(string $backupId): BackupTelegramExportReceipt
    {
        if (! $this->configuration->telegramExportEnabled) {
            throw new RuntimeException('Backup Telegram export is disabled.');
        }

        $metadata = $this->repository->completedArtifactMetadata($backupId);
        $partBytes = $this->configuration->telegramPartBytes;
        $partCount = intdiv($metadata['bytes'] + $partBytes - 1, $partBytes);

        if ($partCount < 1 || $partCount > self::MAX_PARTS) {
            throw new RuntimeException('The backup Telegram export part count is outside the supported bound.');
        }

        $parts = [];
        for ($index = 1; $index <= $partCount; $index++) {
            $offset = ($index - 1) * $partBytes;
            $length = min($partBytes, $metadata['bytes'] - $offset);
            if ($length < 1) {
                throw new RuntimeException('The backup Telegram export part boundary is invalid.');
            }

            $bytes = $this->repository->readArtifactSlice($backupId, $offset, $length);
            if (strlen($bytes) !== $length) {
                throw new RuntimeException('The backup Telegram export part length changed while preparing.');
            }

            $parts[] = [
                'index' => $index,
                'offset' => $offset,
                'bytes' => $length,
                'sha256' => hash('sha256', $bytes),
                'filename' => sprintf(
                    'backup-%s.part-%06d-of-%06d.fbk',
                    $backupId,
                    $index,
                    $partCount,
                ),
            ];
        }

        $manifest = [
            'version' => 1,
            'authority' => self::AUTHORITY,
            'backup_id' => $backupId,
            'completed_at' => $metadata['completed_at'],
            'artifact' => [
                'filename' => $metadata['filename'],
                'bytes' => $metadata['bytes'],
                'sha256' => $metadata['sha256'],
            ],
            'part_bytes' => $partBytes,
            'part_count' => $partCount,
            'parts' => $parts,
        ];
        $manifestJson = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n";
        $this->repository->storeTelegramExportManifest($backupId, $manifestJson);

        $manifestHash = hash('sha256', $manifestJson);
        $destination = $this->destination->resolve();
        $correlationId = 'backup-export-'.substr(
            hash('sha256', $backupId.'|'.$metadata['sha256']),
            0,
            40,
        );

        $operations = [];
        $operations[] = $this->delivery->queue(
            $destination['recipient_chat_id'],
            $backupId,
            'manifest',
            0,
            $partCount,
            strlen($manifestJson),
            $manifestHash,
            'backup-export:'.$backupId.':manifest:'.$manifestHash,
            $correlationId,
        );

        foreach ($parts as $part) {
            $operations[] = $this->delivery->queue(
                $destination['recipient_chat_id'],
                $backupId,
                'part',
                $part['index'],
                $partCount,
                $part['bytes'],
                $part['sha256'],
                'backup-export:'.$backupId.':part:'.$part['index'].':'.$part['sha256'],
                $correlationId,
            );
        }

        return new BackupTelegramExportReceipt(
            $backupId,
            $partCount,
            $partBytes,
            $manifestHash,
            $operations,
        );
    }
}
