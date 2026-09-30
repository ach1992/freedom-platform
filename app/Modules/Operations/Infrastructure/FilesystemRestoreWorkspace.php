<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreWorkspace;
use FilesystemIterator;
use RuntimeException;
use Throwable;

final readonly class FilesystemRestoreWorkspace implements RestoreWorkspace
{
    private const OPERATIONAL_REPORT_SCAN_LIMIT = 64;

    public function __construct(private string $configuredBackupRoot) {}

    public function create(string $restoreRunId): string
    {
        $this->assertRunId($restoreRunId);
        $root = $this->restoreRoot();
        $path = $root.'/work/restore-'.$restoreRunId;

        if (file_exists($path) || is_link($path) || ! mkdir($path, 0700) || ! chmod($path, 0700)) {
            throw new RuntimeException('The restore workspace could not be created securely.');
        }

        return $path;
    }

    public function discard(string $workspacePath): void
    {
        $work = realpath($this->restoreRoot().'/work');
        $parent = realpath(dirname($workspacePath));

        if ($work === false
            || $parent !== $work
            || preg_match('/\Arestore-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', basename($workspacePath)) !== 1
        ) {
            throw new RuntimeException('The restore workspace is outside restore authority.');
        }

        if (file_exists($workspacePath) || is_link($workspacePath)) {
            $this->removeTree($workspacePath);
        }
    }

    /** @param array<string, mixed> $report */
    public function storeReport(string $restoreRunId, array $report): void
    {
        $this->assertRunId($restoreRunId);
        $reports = $this->restoreRoot().'/reports';
        $path = $reports.'/restore-'.$restoreRunId.'.json';

        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('The restore report path is unsafe.');
        }

        $contents = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (strlen($contents) > 1_000_000) {
            throw new RuntimeException('The restore report exceeds its protected bound.');
        }

        $temporary = tempnam($reports, '.restore-report-');
        if ($temporary === false) {
            throw new RuntimeException('The restore report temporary file could not be created.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)
            ) {
                throw new RuntimeException('The restore report could not be persisted.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function operationalStatus(): array
    {
        $reports = $this->readOnlyReportsDirectory();
        if ($reports === null) {
            return [
                'latest_status' => null,
                'latest_failure_code' => null,
                'latest_completed_at' => null,
                'report_inventory_complete' => true,
                'inspected_entries' => 0,
            ];
        }

        $latestRunId = null;
        $inspectionComplete = true;
        $inspectedEntries = 0;
        foreach (new FilesystemIterator($reports, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($inspectedEntries >= self::OPERATIONAL_REPORT_SCAN_LIMIT) {
                $inspectionComplete = false;
                break;
            }
            $inspectedEntries++;

            if (preg_match('/\\Arestore-([0-9]{8}T[0-9]{6}Z-[a-f0-9]{16})\\.json\\z/', $entry->getFilename(), $matches) !== 1) {
                continue;
            }
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new RuntimeException('A protected restore report path is unsafe.');
            }

            $runId = $matches[1];
            if ($latestRunId === null || strcmp($runId, $latestRunId) > 0) {
                $latestRunId = $runId;
            }
        }

        if ($latestRunId === null) {
            return [
                'latest_status' => null,
                'latest_failure_code' => null,
                'latest_completed_at' => null,
                'report_inventory_complete' => $inspectionComplete,
                'inspected_entries' => $inspectedEntries,
            ];
        }

        $runId = $latestRunId;
        $path = $reports.'/restore-'.$runId.'.json';
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('The protected restore report path is unsafe.');
        }
        $bytes = filesize($path);
        if (! is_int($bytes) || $bytes < 1 || $bytes > 1_000_000) {
            throw new RuntimeException('The protected restore report size is invalid.');
        }

        try {
            $contents = file_get_contents($path);
            $decoded = is_string($contents)
                ? json_decode($contents, true, 32, JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable $throwable) {
            throw new RuntimeException('The protected restore report is invalid.', 0, $throwable);
        }
        if (! is_array($decoded)
            || array_is_list($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ($decoded['restore_run_id'] ?? null) !== $runId
        ) {
            throw new RuntimeException('The protected restore report is malformed.');
        }

        return [
            'latest_status' => $this->safeOperationalToken($decoded['status'] ?? null, 'restore status'),
            'latest_failure_code' => $this->safeOperationalToken($decoded['failure_code'] ?? null, 'restore failure code', true),
            'latest_completed_at' => $this->safeOperationalTimestamp($decoded['completed_at'] ?? null),
            'report_inventory_complete' => $inspectionComplete,
            'inspected_entries' => $inspectedEntries,
        ];
    }

    private function readOnlyReportsDirectory(): ?string
    {
        if (! str_starts_with($this->configuredBackupRoot, DIRECTORY_SEPARATOR)
            || is_link($this->configuredBackupRoot)
        ) {
            throw new RuntimeException('The restore authority root is invalid.');
        }
        if (! file_exists($this->configuredBackupRoot)) {
            return null;
        }

        $backupRoot = realpath($this->configuredBackupRoot);
        if ($backupRoot === false || ! is_dir($backupRoot)) {
            throw new RuntimeException('The restore authority root is unavailable.');
        }

        $restorePath = $backupRoot.'/restore';
        if (is_link($restorePath)) {
            throw new RuntimeException('The restore authority directory is unsafe.');
        }
        if (! file_exists($restorePath)) {
            return null;
        }

        $restore = realpath($restorePath);
        if ($restore === false || ! is_dir($restore) || dirname($restore) !== $backupRoot) {
            throw new RuntimeException('The restore authority directory is unsafe.');
        }

        $reportsPath = $restore.'/reports';
        if (is_link($reportsPath)) {
            throw new RuntimeException('A restore authority subdirectory is unsafe.');
        }
        if (! file_exists($reportsPath)) {
            return null;
        }

        $reports = realpath($reportsPath);
        if ($reports === false || ! is_dir($reports) || dirname($reports) !== $restore) {
            throw new RuntimeException('A restore authority subdirectory is unsafe.');
        }

        return $reports;
    }

    private function safeOperationalToken(mixed $value, string $label, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_string($value) || preg_match('/\\A[a-z0-9_.:-]{1,64}\\z/', $value) !== 1) {
            throw new RuntimeException('The protected '.$label.' is invalid.');
        }

        return $value;
    }

    private function safeOperationalTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || strlen($value) > 64 || preg_match('/[\\x00-\\x1F\\x7F]/', $value) === 1) {
            throw new RuntimeException('The protected restore completed timestamp is invalid.');
        }

        return $value;
    }

    private function restoreRoot(): string
    {
        if (! str_starts_with($this->configuredBackupRoot, DIRECTORY_SEPARATOR)
            || is_link($this->configuredBackupRoot)
        ) {
            throw new RuntimeException('The restore authority root is invalid.');
        }

        if (! is_dir($this->configuredBackupRoot)
            && ! mkdir($this->configuredBackupRoot, 0700, true)
            && ! is_dir($this->configuredBackupRoot)
        ) {
            throw new RuntimeException('The restore authority root could not be created.');
        }

        $backupRoot = realpath($this->configuredBackupRoot);
        if ($backupRoot === false || ! is_dir($backupRoot) || ! chmod($backupRoot, 0700)) {
            throw new RuntimeException('The restore authority root is unavailable.');
        }

        $restore = $backupRoot.'/restore';
        if (! is_dir($restore) && ! mkdir($restore, 0700) && ! is_dir($restore)) {
            throw new RuntimeException('The restore authority directory could not be created.');
        }
        if (is_link($restore) || realpath(dirname($restore)) !== $backupRoot || ! chmod($restore, 0700)) {
            throw new RuntimeException('The restore authority directory is unsafe.');
        }

        foreach (['work', 'reports'] as $name) {
            $path = $restore.'/'.$name;
            if (! is_dir($path) && ! mkdir($path, 0700) && ! is_dir($path)) {
                throw new RuntimeException('A restore authority subdirectory could not be created.');
            }
            if (is_link($path) || realpath(dirname($path)) !== realpath($restore) || ! chmod($path, 0700)) {
                throw new RuntimeException('A restore authority subdirectory is unsafe.');
            }
        }

        return $restore;
    }

    private function assertRunId(string $restoreRunId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $restoreRunId) !== 1) {
            throw new RuntimeException('The restore run identifier is invalid.');
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('The restore workspace contains an unsafe symbolic link.');
        }

        if (is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('A restore workspace file could not be removed.');
            }

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

        if (! rmdir($path)) {
            throw new RuntimeException('A restore workspace directory could not be removed.');
        }
    }
}
