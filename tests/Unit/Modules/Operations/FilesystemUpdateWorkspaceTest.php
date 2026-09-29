<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\FilesystemUpdateWorkspace;
use RuntimeException;
use Tests\TestCase;

final class FilesystemUpdateWorkspaceTest extends TestCase
{
    /** @requirement UPD-001 RUN-002 SEC-001 QUA-001 */
    public function test_reports_are_atomic_protected_and_secret_free_by_contract_payload(): void
    {
        $root = $this->deployment('report');
        $workspace = new FilesystemUpdateWorkspace($root);
        $runId = '20260930T010203Z-0123456789abcdef';

        try {
            $workspace->storeReport($runId, [
                'version' => 1,
                'update_run_id' => $runId,
                'status' => 'running',
                'report_finalized' => false,
                'release_id' => '1.1.0',
            ]);

            $path = $root.'/shared/update-reports/update-'.$runId.'.json';
            self::assertFileExists($path);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(0700, fileperms(dirname($path)) & 0777);
            self::assertStringNotContainsString('APP_KEY', (string) file_get_contents($path));
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement UPD-001 RUN-002 SEC-008 QUA-001 */
    public function test_published_release_is_sealed_read_only_except_runtime_cache_and_can_be_pruned_safely(): void
    {
        $root = $this->deployment('seal');
        $workspace = new FilesystemUpdateWorkspace($root);
        $runId = '20260930T010207Z-0123456789abcdef';

        try {
            $staging = $workspace->createStaging($runId, '1.1.0');
            file_put_contents($staging.'/release-manifest.json', json_encode([
                'version' => 1,
                'authority' => 'freedom_platform_release_v1',
                'release_id' => '1.1.0',
            ], JSON_THROW_ON_ERROR));
            file_put_contents($staging.'/code.php', "<?php\n");
            mkdir($staging.'/deploy/bin', 0750, true);
            file_put_contents($staging.'/deploy/bin/worker.sh', "#!/usr/bin/env bash\nexit 0\n");
            chmod($staging.'/deploy/bin/worker.sh', 0750);
            mkdir($staging.'/bootstrap/cache', 0750, true);
            file_put_contents($staging.'/bootstrap/cache/packages.php', "<?php\nreturn [];\n");

            $published = $workspace->publishRelease($staging, '1.1.0');
            $workspace->sealPublishedRelease('1.1.0');

            self::assertSame(0550, fileperms($published) & 0777);
            self::assertSame(0440, fileperms($published.'/code.php') & 0777);
            self::assertSame(0550, fileperms($published.'/deploy/bin/worker.sh') & 0777);
            self::assertSame(0550, fileperms($published.'/bootstrap') & 0777);
            self::assertSame(0750, fileperms($published.'/bootstrap/cache') & 0777);
            self::assertSame(0640, fileperms($published.'/bootstrap/cache/packages.php') & 0777);

            $workspace->discardInactiveRelease('1.1.0');
            self::assertDirectoryDoesNotExist($published);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement UPD-001 RUN-002 QUA-001 */
    public function test_interrupted_pre_mutation_staging_is_cleaned_and_report_is_finalized(): void
    {
        $root = $this->deployment('recover-staging');
        $workspace = new FilesystemUpdateWorkspace($root);
        $runId = '20260930T010204Z-0123456789abcdef';

        try {
            $staging = $workspace->createStaging($runId, '1.1.0');
            file_put_contents($staging.'/partial.txt', 'partial');
            $workspace->storeReport($runId, $this->report($runId, '1.1.0', staging: $staging));

            $workspace->recoverInterruptedPreMutationRuns();

            self::assertDirectoryDoesNotExist($staging);
            $report = $this->readReport($root, $runId);
            self::assertSame('interrupted_pre_mutation_recovered', $report['status']);
            self::assertTrue($report['report_finalized']);
            self::assertSame('interrupted_before_mutation', $report['failure_code']);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement UPD-001 RUN-002 QUA-001 */
    public function test_interrupted_published_but_inactive_candidate_is_removed_for_safe_reentry(): void
    {
        $root = $this->deployment('recover-published');
        $workspace = new FilesystemUpdateWorkspace($root);
        $runId = '20260930T010205Z-0123456789abcdef';

        try {
            $this->release($root, '1.0.0');
            symlink('releases/1.0.0', $root.'/current');
            $this->release($root, '1.1.0');
            $report = $this->report($runId, '1.1.0');
            $report['release_published'] = true;
            $workspace->storeReport($runId, $report);

            $workspace->recoverInterruptedPreMutationRuns();

            self::assertDirectoryDoesNotExist($root.'/releases/1.1.0');
            self::assertSame('1.0.0', $workspace->currentReleaseId());
            self::assertFalse($this->readReport($root, $runId)['release_published']);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement UPD-001 RUN-002 OPS-003 QUA-001 */
    public function test_interrupted_mutation_is_never_auto_recovered_or_reported_successfully(): void
    {
        $root = $this->deployment('recover-mutation');
        $workspace = new FilesystemUpdateWorkspace($root);
        $runId = '20260930T010206Z-0123456789abcdef';

        try {
            $report = $this->report($runId, '1.1.0');
            $report['mutation_started'] = true;
            $workspace->storeReport($runId, $report);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('requires operator recovery');
            $workspace->recoverInterruptedPreMutationRuns();
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement UPD-001 RUN-002 SEC-001 QUA-001 */
    public function test_installed_identity_rejects_malformed_or_duplicated_rollback_metadata(): void
    {
        $root = $this->deployment('identity');
        $workspace = new FilesystemUpdateWorkspace($root);
        $schema = str_repeat('a', 64);

        try {
            $workspace->storeInstalledIdentity([
                'version' => 1,
                'release_id' => 'build-100',
                'application_version' => '1.0.0',
                'previous_release' => 'build-099',
                'previous_application_version' => '0.9.9',
                'schema_sha256' => $schema,
                'rollback_compatible_schema_sha256' => [$schema],
                'pre_update_backup_id' => '20260930T000000Z-abcdef0123456789',
            ]);
            self::assertSame('build-100', $workspace->installedIdentity()['release_id'] ?? null);

            try {
                $workspace->storeInstalledIdentity([
                    'version' => 1,
                    'release_id' => 'build-101',
                    'application_version' => '1.0.1',
                    'schema_sha256' => $schema,
                    'rollback_compatible_schema_sha256' => [$schema, $schema],
                ]);
                self::fail('Duplicate rollback compatibility identities must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame('The installed release identity is invalid.', $exception->getMessage());
            }
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement UPD-001 RUN-002 QUA-001 */
    public function test_retention_only_prunes_controlled_inactive_releases_and_preserves_protected_releases(): void
    {
        $root = $this->deployment('retention');
        $workspace = new FilesystemUpdateWorkspace($root);

        try {
            foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0'] as $index => $release) {
                $this->release($root, $release);
                touch($root.'/releases/'.$release, 1_000 + $index);
            }
            mkdir($root.'/releases/operator-owned', 0700);
            file_put_contents($root.'/releases/operator-owned/keep.txt', 'keep');
            symlink('releases/1.3.0', $root.'/current');

            $workspace->pruneReleases(['1.3.0', '1.0.0'], 2);

            self::assertDirectoryExists($root.'/releases/1.3.0');
            self::assertDirectoryExists($root.'/releases/1.2.0');
            self::assertDirectoryDoesNotExist($root.'/releases/1.1.0');
            self::assertDirectoryExists($root.'/releases/1.0.0');
            self::assertDirectoryExists($root.'/releases/operator-owned');
        } finally {
            $this->removeTree($root);
        }
    }

    private function deployment(string $case): string
    {
        $root = storage_path('framework/testing/update-workspace-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($root.'/releases', 0700, true);
        mkdir($root.'/shared/storage', 0700, true);

        return $root;
    }

    private function release(string $root, string $releaseId): void
    {
        $path = $root.'/releases/'.$releaseId;
        mkdir($path, 0700, true);
        file_put_contents($path.'/release-manifest.json', json_encode([
            'version' => 1,
            'authority' => 'freedom_platform_release_v1',
            'release_id' => $releaseId,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function report(string $runId, string $releaseId, ?string $staging = null): array
    {
        return [
            'version' => 1,
            'update_run_id' => $runId,
            'operation' => 'update',
            'status' => 'running',
            'report_finalized' => false,
            'release_id' => $releaseId,
            'staging_path' => $staging,
            'release_published' => false,
            'mutation_started' => false,
            'activation_started' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function readReport(string $root, string $runId): array
    {
        $decoded = json_decode(
            (string) file_get_contents($root.'/shared/update-reports/update-'.$runId.'.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
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

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
