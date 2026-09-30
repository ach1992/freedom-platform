<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\FilesystemRestoreWorkspace;
use Tests\TestCase;

final class FilesystemRestoreWorkspaceTest extends TestCase
{
    /** @requirement OPS-002 RST-001 QUA-001 */
    public function test_operational_status_is_read_only_and_bounds_report_inventory(): void
    {
        $base = storage_path('framework/testing/restore-workspace-status-'.bin2hex(random_bytes(4)));
        $backupRoot = $base.'/backups';
        $workspace = new FilesystemRestoreWorkspace($backupRoot);

        mkdir($base, 0700, true);

        try {
            self::assertDirectoryDoesNotExist($backupRoot);
            self::assertSame([
                'latest_status' => null,
                'latest_failure_code' => null,
                'latest_completed_at' => null,
                'report_inventory_complete' => true,
                'inspected_entries' => 0,
            ], $workspace->operationalStatus());
            self::assertDirectoryDoesNotExist($backupRoot);

            $reports = $backupRoot.'/restore/reports';
            mkdir($reports, 0750, true);
            chmod($backupRoot, 0750);
            chmod($backupRoot.'/restore', 0750);
            chmod($reports, 0750);
            clearstatcache(true, $backupRoot);
            clearstatcache(true, $backupRoot.'/restore');
            clearstatcache(true, $reports);
            $rootMode = fileperms($backupRoot) & 0777;
            $restoreMode = fileperms($backupRoot.'/restore') & 0777;
            $reportsMode = fileperms($reports) & 0777;

            $completedAtValues = [];
            for ($index = 0; $index < 65; $index++) {
                $runId = sprintf('20260930T%06dZ-%016x', $index + 1, $index + 1);
                $completedAt = sprintf('2026-09-30T00:02:%02d+00:00', $index % 60);
                $completedAtValues[] = $completedAt;
                file_put_contents(
                    $reports.'/restore-'.$runId.'.json',
                    json_encode([
                        'version' => 1,
                        'restore_run_id' => $runId,
                        'status' => 'completed',
                        'failure_code' => null,
                        'completed_at' => $completedAt,
                    ], JSON_THROW_ON_ERROR),
                );
            }

            $status = $workspace->operationalStatus();
            self::assertFalse($status['report_inventory_complete']);
            self::assertSame(64, $status['inspected_entries']);
            self::assertSame('completed', $status['latest_status']);
            self::assertContains($status['latest_completed_at'], $completedAtValues);

            clearstatcache(true, $backupRoot);
            clearstatcache(true, $backupRoot.'/restore');
            clearstatcache(true, $reports);
            self::assertSame($rootMode, fileperms($backupRoot) & 0777);
            self::assertSame($restoreMode, fileperms($backupRoot.'/restore') & 0777);
            self::assertSame($reportsMode, fileperms($reports) & 0777);
        } finally {
            $this->removeTree($base);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.'/'.$name);
            }
        }
        @rmdir($path);
    }
}
