<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\BackupRuntimeConfiguration;
use App\Modules\Operations\Application\BackupTelegramExportService;
use App\Modules\Operations\Application\Contracts\BackupTelegramDeliveryQueue;
use App\Modules\Operations\Application\Contracts\BackupTelegramOwnerDestinationResolver;
use App\Modules\Operations\Infrastructure\FilesystemBackupRepository;
use RuntimeException;
use Tests\TestCase;

final class RecordingBackupTelegramDeliveryQueue implements BackupTelegramDeliveryQueue
{
    /** @var list<array<string,int|string>> */
    public array $calls = [];

    public function queue(
        int $recipientChatId,
        string $backupId,
        string $item,
        int $partIndex,
        int $partCount,
        int $contentBytes,
        string $contentSha256,
        string $requestKey,
        string $correlationId,
    ): string {
        $this->calls[] = [
            'recipient_chat_id' => $recipientChatId,
            'backup_id' => $backupId,
            'item' => $item,
            'part_index' => $partIndex,
            'part_count' => $partCount,
            'content_bytes' => $contentBytes,
            'content_sha256' => $contentSha256,
            'request_key' => $requestKey,
            'correlation_id' => $correlationId,
        ];

        return 'operation-'.substr(hash('sha256', $requestKey), 0, 20);
    }
}

final class InterruptingBackupTelegramDeliveryQueue implements BackupTelegramDeliveryQueue
{
    /** @var list<array<string,int|string>> */
    public array $calls = [];

    public function __construct(private readonly int $failOnCall) {}

    public function queue(
        int $recipientChatId,
        string $backupId,
        string $item,
        int $partIndex,
        int $partCount,
        int $contentBytes,
        string $contentSha256,
        string $requestKey,
        string $correlationId,
    ): string {
        $this->calls[] = [
            'recipient_chat_id' => $recipientChatId,
            'backup_id' => $backupId,
            'item' => $item,
            'part_index' => $partIndex,
            'part_count' => $partCount,
            'content_bytes' => $contentBytes,
            'content_sha256' => $contentSha256,
            'request_key' => $requestKey,
            'correlation_id' => $correlationId,
        ];

        if (count($this->calls) === $this->failOnCall) {
            throw new RuntimeException('Simulated interrupted backup Telegram queueing.');
        }

        return 'operation-'.substr(hash('sha256', $requestKey), 0, 20);
    }
}

final readonly class FixedBackupTelegramOwnerDestinationResolver implements BackupTelegramOwnerDestinationResolver
{
    /** @return array{user_id:int,recipient_chat_id:int} */
    public function resolve(): array
    {
        return ['user_id' => 7, 'recipient_chat_id' => 900001];
    }
}

final class BackupTelegramExportServiceTest extends TestCase
{
    /** @requirement BAK-001 SEC-001 SEC-002 OPS-003 QUA-001 */
    public function test_export_builds_exact_reassembly_manifest_and_replay_stable_delivery_references(): void
    {
        $base = $this->directory('multipart');
        $root = $base.'/backups';
        $repository = new FilesystemBackupRepository($root);
        $backupId = '20260929T050500Z-1111111111111111';
        $artifact = '0123456789ABCDEFGHIJklmnopqrstu';
        $this->completedBackup($repository, $backupId, $artifact);
        $delivery = new RecordingBackupTelegramDeliveryQueue;
        $service = new BackupTelegramExportService(
            $this->configuration($root, true, 10),
            $repository,
            new FixedBackupTelegramOwnerDestinationResolver,
            $delivery,
        );

        try {
            $first = $service->queue($backupId);
            $second = $service->queue($backupId);

            self::assertSame(4, $first->partCount);
            self::assertSame(10, $first->partBytes);
            self::assertSame($first->deliveryOperationPublicIds, $second->deliveryOperationPublicIds);
            self::assertCount(10, $delivery->calls);

            $manifestJson = $repository->telegramExportManifest($backupId);
            self::assertSame(hash('sha256', $manifestJson), $first->manifestSha256);
            $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('freedom_platform_backup_telegram_v1', $manifest['authority']);
            self::assertSame($backupId, $manifest['backup_id']);
            self::assertSame(strlen($artifact), $manifest['artifact']['bytes']);
            self::assertSame(hash('sha256', $artifact), $manifest['artifact']['sha256']);
            self::assertSame(4, $manifest['part_count']);

            $reassembled = '';
            foreach ($manifest['parts'] as $part) {
                self::assertLessThanOrEqual(10, $part['bytes']);
                $slice = $repository->readArtifactSlice($backupId, $part['offset'], $part['bytes']);
                self::assertSame($part['sha256'], hash('sha256', $slice));
                $reassembled .= $slice;
            }
            self::assertSame($artifact, $reassembled);
            self::assertSame(hash('sha256', $artifact), hash('sha256', $reassembled));

            $firstRunCalls = array_slice($delivery->calls, 0, 5);
            $secondRunCalls = array_slice($delivery->calls, 5, 5);
            self::assertSame($firstRunCalls, $secondRunCalls);
            self::assertSame('manifest', $firstRunCalls[0]['item']);
            self::assertSame(0, $firstRunCalls[0]['part_index']);
            self::assertSame(900001, $firstRunCalls[0]['recipient_chat_id']);
            foreach (array_slice($firstRunCalls, 1) as $offset => $call) {
                self::assertSame('part', $call['item']);
                self::assertSame($offset + 1, $call['part_index']);
                self::assertLessThanOrEqual(10, $call['content_bytes']);
            }
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-001 OPS-003 QUA-010 */
    public function test_interrupted_telegram_export_keeps_local_backup_authoritative_and_replay_stable(): void
    {
        $base = $this->directory('interrupted-export');
        $root = $base.'/backups';
        $repository = new FilesystemBackupRepository($root);
        $backupId = '20260929T050500Z-2222222222222222';
        $artifact = '0123456789ABCDEFGHIJklmnopqrstu';
        $this->completedBackup($repository, $backupId, $artifact);
        $before = $repository->completedArtifactMetadata($backupId);
        $interrupted = new InterruptingBackupTelegramDeliveryQueue(3);
        $service = new BackupTelegramExportService(
            $this->configuration($root, true, 10),
            $repository,
            new FixedBackupTelegramOwnerDestinationResolver,
            $interrupted,
        );

        try {
            try {
                $service->queue($backupId);
                self::fail('Interrupted Telegram export queueing must surface its failure.');
            } catch (RuntimeException $exception) {
                self::assertSame('Simulated interrupted backup Telegram queueing.', $exception->getMessage());
            }

            self::assertCount(3, $interrupted->calls);
            self::assertSame($before, $repository->completedArtifactMetadata($backupId));
            self::assertSame($artifact, $repository->readArtifactSlice($backupId, 0, strlen($artifact)));

            $replayQueue = new RecordingBackupTelegramDeliveryQueue;
            $replay = new BackupTelegramExportService(
                $this->configuration($root, true, 10),
                $repository,
                new FixedBackupTelegramOwnerDestinationResolver,
                $replayQueue,
            );
            $receipt = $replay->queue($backupId);

            self::assertSame(4, $receipt->partCount);
            self::assertCount(5, $replayQueue->calls);
            foreach ($interrupted->calls as $index => $call) {
                self::assertSame($call['request_key'], $replayQueue->calls[$index]['request_key']);
                self::assertSame($call['correlation_id'], $replayQueue->calls[$index]['correlation_id']);
                self::assertSame($call['content_sha256'], $replayQueue->calls[$index]['content_sha256']);
            }
            self::assertSame($before, $repository->completedArtifactMetadata($backupId));
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_export_is_fail_closed_when_optional_telegram_delivery_is_disabled(): void
    {
        $base = $this->directory('disabled');
        $repository = new FilesystemBackupRepository($base.'/backups');
        $service = new BackupTelegramExportService(
            $this->configuration($base.'/backups', false, 10),
            $repository,
            new FixedBackupTelegramOwnerDestinationResolver,
            new RecordingBackupTelegramDeliveryQueue,
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backup Telegram export is disabled.');
            $service->queue('20260929T050500Z-1111111111111111');
        } finally {
            $this->removeTree($base);
        }
    }

    private function configuration(string $root, bool $telegramEnabled, int $partBytes): BackupRuntimeConfiguration
    {
        return new BackupRuntimeConfiguration(
            false,
            $root,
            null,
            30,
            10,
            '02:30',
            30,
            180,
            1800,
            $telegramEnabled,
            $partBytes,
            [],
            [],
        );
    }

    private function completedBackup(
        FilesystemBackupRepository $repository,
        string $backupId,
        string $artifactContents,
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
                'completed_at' => '2026-09-29T05:05:00+00:00',
                'artifact' => [
                    'filename' => $filename,
                    'bytes' => strlen($artifactContents),
                    'sha256' => hash('sha256', $artifactContents),
                ],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/backup-telegram-export-'.$case.'-'.bin2hex(random_bytes(4)));
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
