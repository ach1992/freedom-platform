<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\UpdateWorkspace;
use RuntimeException;
use Throwable;

final readonly class FilesystemUpdateWorkspace implements UpdateWorkspace
{
    private const REPORT_VERSION = 1;

    private const IDENTITY_VERSION = 1;

    public function __construct(private string $deploymentRoot) {}

    /** @requirement UPD-001 RUN-002 SEC-001 QUA-001 */
    public function recoverInterruptedPreMutationRuns(): void
    {
        $root = $this->root();
        $reportsDirectory = $this->reportsDirectory($root);
        $files = glob($reportsDirectory.'/update-*.json');
        if ($files === false) {
            throw new RuntimeException('Update reports could not be inspected.');
        }

        foreach ($files as $path) {
            if (is_link($path) || ! is_file($path)) {
                throw new RuntimeException('An update report path is unsafe.');
            }

            $report = $this->readJson($path, 'An update report is invalid.');
            if (($report['version'] ?? null) !== self::REPORT_VERSION
                || ! is_string($report['update_run_id'] ?? null)
                || preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $report['update_run_id']) !== 1
                || ! is_bool($report['report_finalized'] ?? null)
            ) {
                throw new RuntimeException('An update report is malformed.');
            }
            if ($report['report_finalized']) {
                continue;
            }

            $releaseId = $report['release_id'] ?? null;
            $mutationStarted = ($report['mutation_started'] ?? null) === true;
            $activationStarted = ($report['activation_started'] ?? null) === true;

            if (! is_string($releaseId)
                || ! $this->validReleaseId($releaseId)
                || $mutationStarted
                || $activationStarted
                || hash_equals((string) ($this->currentReleaseId() ?? ''), $releaseId)
            ) {
                throw new RuntimeException('A previous update has unresolved mutation state and requires operator recovery.');
            }

            $staging = $report['staging_path'] ?? null;
            if ($staging !== null) {
                if (! is_string($staging) || ! $this->isOwnedStagingPath($root, $staging, $report['update_run_id'], $releaseId)) {
                    throw new RuntimeException('An interrupted update staging path is unsafe.');
                }
                if (file_exists($staging) || is_link($staging)) {
                    $this->removeTree($staging);
                }
            }

            if (($report['release_published'] ?? false) === true) {
                $this->discardInactiveRelease($releaseId);
                $report['release_published'] = false;
            }

            $report['status'] = 'interrupted_pre_mutation_recovered';
            $report['report_finalized'] = true;
            $report['completed_at'] = gmdate(DATE_ATOM);
            $report['failure_code'] = 'interrupted_before_mutation';
            $this->atomicJsonWrite($path, $report);
        }
    }

    /** @param array<string, mixed> $report */
    public function storeReport(string $updateRunId, array $report): void
    {
        $this->assertRunId($updateRunId);
        if (($report['version'] ?? null) !== self::REPORT_VERSION
            || ($report['update_run_id'] ?? null) !== $updateRunId
        ) {
            throw new RuntimeException('The update report identity is invalid.');
        }

        $path = $this->reportsDirectory($this->root()).'/update-'.$updateRunId.'.json';
        $this->atomicJsonWrite($path, $report);
    }

    /** @requirement UPD-001 RUN-002 SEC-008 QUA-001 */
    public function createStaging(string $updateRunId, string $releaseId): string
    {
        $this->assertRunId($updateRunId);
        $this->assertReleaseId($releaseId);

        $releases = $this->releasesDirectory($this->root());
        $path = $releases.'/.update-'.$updateRunId.'-'.$releaseId;

        if (file_exists($path) || is_link($path)
            || ! mkdir($path, 0750)
            || ! is_dir($path)
        ) {
            throw new RuntimeException('The update staging directory could not be prepared.');
        }

        return $path;
    }

    /** @requirement UPD-001 RUN-002 SEC-008 QUA-001 */
    public function publishRelease(string $stagingPath, string $releaseId): string
    {
        $this->assertReleaseId($releaseId);
        $root = $this->root();
        $releases = $this->releasesDirectory($root);
        $staging = realpath($stagingPath);

        if ($staging === false
            || ! is_dir($staging)
            || is_link($stagingPath)
            || dirname($staging) !== $releases
            || ! str_starts_with(basename($staging), '.update-')
        ) {
            throw new RuntimeException('The update staging release is outside authority.');
        }

        $destination = $releases.'/'.$releaseId;
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('The candidate release identifier already exists.');
        }

        if (! rename($staging, $destination)) {
            throw new RuntimeException('The staged release could not be published atomically.');
        }

        $resolved = realpath($destination);
        if ($resolved === false || dirname($resolved) !== $releases || basename($resolved) !== $releaseId) {
            throw new RuntimeException('The published release path could not be proven.');
        }

        return $resolved;
    }

    /** @requirement UPD-001 RUN-002 SEC-008 QUA-001 */
    public function sealPublishedRelease(string $releaseId): void
    {
        $this->assertReleaseId($releaseId);

        if ($this->currentReleaseId() === $releaseId) {
            throw new RuntimeException('The active release cannot be sealed by the staging authority.');
        }

        $releases = $this->releasesDirectory($this->root());
        $path = $releases.'/'.$releaseId;
        $resolved = realpath($path);

        if ($resolved === false
            || ! is_dir($resolved)
            || is_link($path)
            || dirname($resolved) !== $releases
            || basename($resolved) !== $releaseId
        ) {
            throw new RuntimeException('The published release is unavailable or outside authority.');
        }

        $manifest = $this->readJson($resolved.'/release-manifest.json', 'The published release manifest is invalid.');
        if (($manifest['authority'] ?? null) !== 'freedom_platform_release_v1'
            || ($manifest['release_id'] ?? null) !== $releaseId
        ) {
            throw new RuntimeException('The published release is not owned by the controlled updater.');
        }

        $this->sealTree($resolved, $resolved, $resolved.'/bootstrap/cache');
    }

    public function discardStaging(string $stagingPath): void
    {
        $root = $this->root();
        $releases = $this->releasesDirectory($root);
        $candidate = realpath($stagingPath);

        if ($candidate === false) {
            if (! file_exists($stagingPath) && ! is_link($stagingPath)) {
                return;
            }
            throw new RuntimeException('The update staging path could not be resolved safely.');
        }

        if (! is_dir($candidate)
            || is_link($stagingPath)
            || dirname($candidate) !== $releases
            || ! str_starts_with(basename($candidate), '.update-')
        ) {
            throw new RuntimeException('The update staging path is outside authority.');
        }

        $this->removeTree($candidate);
    }

    public function discardInactiveRelease(string $releaseId): void
    {
        $this->assertReleaseId($releaseId);
        if ($this->currentReleaseId() === $releaseId) {
            throw new RuntimeException('The active release cannot be discarded.');
        }

        $releases = $this->releasesDirectory($this->root());
        $path = $releases.'/'.$releaseId;
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (! is_dir($path) || is_link($path)) {
            throw new RuntimeException('The inactive release path is unsafe.');
        }

        $resolved = realpath($path);
        if ($resolved === false || dirname($resolved) !== $releases || basename($resolved) !== $releaseId) {
            throw new RuntimeException('The inactive release path is outside authority.');
        }

        $manifest = $this->readJson($resolved.'/release-manifest.json', 'The inactive release manifest is invalid.');
        if (($manifest['authority'] ?? null) !== 'freedom_platform_release_v1'
            || ($manifest['release_id'] ?? null) !== $releaseId
        ) {
            throw new RuntimeException('The inactive release is not owned by the controlled updater.');
        }

        $this->removeTree($resolved);
    }

    public function currentReleaseId(): ?string
    {
        $root = $this->root();
        $releases = $this->releasesDirectory($root);
        $current = $root.'/current';

        if (! file_exists($current) && ! is_link($current)) {
            return null;
        }
        if (! is_link($current)) {
            throw new RuntimeException('The current release pointer is not a symlink.');
        }

        $resolved = realpath($current);
        if ($resolved === false || dirname($resolved) !== $releases) {
            throw new RuntimeException('The current release pointer is unsafe.');
        }

        $releaseId = basename($resolved);
        $this->assertReleaseId($releaseId);

        return $releaseId;
    }

    /** @return array<string, mixed>|null */
    public function installedIdentity(): ?array
    {
        $path = $this->root().'/shared/installed-release.json';
        if (! file_exists($path)) {
            return null;
        }
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('The installed release identity path is unsafe.');
        }

        $identity = $this->readJson($path, 'The installed release identity is invalid.');
        $this->assertInstalledIdentity($identity, 'The installed release identity is malformed.');

        return $identity;
    }

    /** @param array<string, mixed> $identity */
    public function storeInstalledIdentity(array $identity): void
    {
        $this->assertInstalledIdentity($identity, 'The installed release identity is invalid.');
        $this->atomicJsonWrite($this->root().'/shared/installed-release.json', $identity);
    }

    /** @param list<string> $protectedReleaseIds */
    public function pruneReleases(array $protectedReleaseIds, int $retention): void
    {
        if ($retention < 2 || $retention > 20) {
            throw new RuntimeException('The release retention request is invalid.');
        }

        foreach ($protectedReleaseIds as $releaseId) {
            $this->assertReleaseId($releaseId);
        }

        $releases = $this->releasesDirectory($this->root());
        $owned = [];
        foreach (scandir($releases) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $path = $releases.'/'.$name;
            if (! is_dir($path) || is_link($path) || ! $this->validReleaseId($name)) {
                continue;
            }

            $manifest = $path.'/release-manifest.json';
            if (! is_file($manifest) || is_link($manifest)) {
                continue;
            }
            $metadata = $this->readJson($manifest, 'A retained release manifest is invalid.');
            if (($metadata['authority'] ?? null) !== 'freedom_platform_release_v1'
                || ($metadata['release_id'] ?? null) !== $name
            ) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime === false) {
                throw new RuntimeException('A retained release timestamp is unavailable.');
            }
            $owned[$name] = $mtime;
        }

        arsort($owned, SORT_NUMERIC);
        $kept = 0;
        foreach ($owned as $releaseId => $mtime) {
            unset($mtime);
            if (in_array($releaseId, $protectedReleaseIds, true) || $kept < $retention) {
                $kept++;

                continue;
            }

            $this->removeTree($releases.'/'.$releaseId);
        }
    }

    private function root(): string
    {
        if (! str_starts_with($this->deploymentRoot, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The update deployment root must be absolute.');
        }

        $root = realpath($this->deploymentRoot);
        if ($root === false || ! is_dir($root) || is_link($this->deploymentRoot)) {
            throw new RuntimeException('The update deployment root is unavailable.');
        }

        $shared = realpath($root.'/shared');
        if ($shared === false || ! is_dir($shared) || dirname($shared) !== $root) {
            throw new RuntimeException('The shared deployment directory is unavailable.');
        }

        return $root;
    }

    private function releasesDirectory(string $root): string
    {
        $releases = realpath($root.'/releases');
        if ($releases === false || ! is_dir($releases) || dirname($releases) !== $root) {
            throw new RuntimeException('The release directory is unavailable.');
        }

        return $releases;
    }

    private function reportsDirectory(string $root): string
    {
        $path = $root.'/shared/update-reports';
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('The protected update report directory could not be prepared.');
        }
        if (is_link($path) || ! chmod($path, 0700)) {
            throw new RuntimeException('The protected update report directory is unsafe.');
        }

        return $path;
    }

    /** @param array<string, mixed> $payload */
    private function atomicJsonWrite(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('The protected update metadata directory is unavailable.');
        }

        try {
            $contents = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            )."\n";
        } catch (Throwable $throwable) {
            throw new RuntimeException('Protected update metadata could not be encoded.', 0, $throwable);
        }

        $temporary = tempnam($directory, '.update-meta-');
        if ($temporary === false) {
            throw new RuntimeException('Protected update metadata staging could not be created.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)
            ) {
                throw new RuntimeException('Protected update metadata could not be written atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path, string $message): array
    {
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException($message);
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException($message, 0, $throwable);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function isOwnedStagingPath(
        string $root,
        string $stagingPath,
        string $updateRunId,
        string $releaseId,
    ): bool {
        $releases = $this->releasesDirectory($root);
        $expected = $releases.'/.update-'.$updateRunId.'-'.$releaseId;

        return hash_equals($expected, $stagingPath)
            && dirname($stagingPath) === $releases
            && str_starts_with(basename($stagingPath), '.update-');
    }

    /** @param array<string,mixed> $identity */
    private function assertInstalledIdentity(array $identity, string $message): void
    {
        $releaseId = $identity['release_id'] ?? null;
        $applicationVersion = $identity['application_version'] ?? null;
        $schemaSha256 = $identity['schema_sha256'] ?? null;
        if (($identity['version'] ?? null) !== self::IDENTITY_VERSION
            || ! is_string($releaseId)
            || ! $this->validReleaseId($releaseId)
            || ! is_string($applicationVersion)
            || ! $this->validBoundedText($applicationVersion, 64)
            || ! is_string($schemaSha256)
            || ! $this->validSha256($schemaSha256)
        ) {
            throw new RuntimeException($message);
        }

        $previousRelease = $identity['previous_release'] ?? null;
        if ($previousRelease !== null
            && (! is_string($previousRelease) || ! $this->validReleaseId($previousRelease))
        ) {
            throw new RuntimeException($message);
        }

        $previousApplicationVersion = $identity['previous_application_version'] ?? null;
        if ($previousApplicationVersion !== null
            && (! is_string($previousApplicationVersion) || ! $this->validBoundedText($previousApplicationVersion, 64))
        ) {
            throw new RuntimeException($message);
        }

        foreach (['package_sha256', 'manifest_sha256', 'composer_lock_sha256'] as $field) {
            $value = $identity[$field] ?? null;
            if ($value !== null && (! is_string($value) || ! $this->validSha256($value))) {
                throw new RuntimeException($message);
            }
        }

        $compatible = $identity['rollback_compatible_schema_sha256'] ?? null;
        if ($compatible !== null) {
            if (! is_array($compatible) || ! array_is_list($compatible) || count($compatible) > 16) {
                throw new RuntimeException($message);
            }
            $seen = [];
            foreach ($compatible as $sha256) {
                if (! is_string($sha256) || ! $this->validSha256($sha256) || in_array($sha256, $seen, true)) {
                    throw new RuntimeException($message);
                }
                $seen[] = $sha256;
            }
        }

        $backupId = $identity['pre_update_backup_id'] ?? null;
        if ($backupId !== null
            && (! is_string($backupId) || ! $this->validBoundedText($backupId, 191))
        ) {
            throw new RuntimeException($message);
        }
    }

    private function validBoundedText(string $value, int $maximum): bool
    {
        return $value !== ''
            && strlen($value) <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function validSha256(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }

    private function assertRunId(string $updateRunId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $updateRunId) !== 1) {
            throw new RuntimeException('The update run identifier is invalid.');
        }
    }

    private function assertReleaseId(string $releaseId): void
    {
        if (! $this->validReleaseId($releaseId)) {
            throw new RuntimeException('The release identifier is invalid.');
        }
    }

    private function validReleaseId(string $releaseId): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $releaseId) === 1
            && ! str_contains($releaseId, '..');
    }

    private function sealTree(string $path, string $releaseRoot, string $mutableCacheRoot): void
    {
        if (is_link($path)) {
            $relative = ltrim(substr($path, strlen($releaseRoot)), DIRECTORY_SEPARATOR);
            if (! in_array($relative, ['.env', 'storage'], true)) {
                throw new RuntimeException('The published release contains an unapproved symbolic link.');
            }

            return;
        }

        $mutable = $path === $mutableCacheRoot
            || str_starts_with($path, $mutableCacheRoot.DIRECTORY_SEPARATOR);

        if (is_file($path)) {
            if (! chmod($path, $mutable ? 0640 : 0440)) {
                throw new RuntimeException('A published release file could not be sealed.');
            }

            return;
        }

        if (! is_dir($path)) {
            throw new RuntimeException('A published release path could not be sealed safely.');
        }

        $entries = scandir($path);
        if (! is_array($entries)) {
            throw new RuntimeException('A published release directory could not be inspected for sealing.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->sealTree($path.'/'.$entry, $releaseRoot, $mutableCacheRoot);
        }

        if (! chmod($path, $mutable ? 0750 : 0550)) {
            throw new RuntimeException('A published release directory could not be sealed.');
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('A release link could not be removed safely.');
            }

            return;
        }
        if (is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('A release file could not be removed safely.');
            }

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        if (! chmod($path, 0750)) {
            throw new RuntimeException('A release directory could not be reopened for controlled removal.');
        }

        $entries = scandir($path);
        if (! is_array($entries)) {
            throw new RuntimeException('A release directory could not be inspected safely.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$entry);
        }

        if (! rmdir($path)) {
            throw new RuntimeException('A release directory could not be removed safely.');
        }
    }
}
