<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\UpdateRuntimeConfiguration;
use App\Modules\Operations\Application\VerifiedUpdatePackage;
use App\Modules\Operations\Infrastructure\ArtisanUpdateReleaseExecutor;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ArtisanUpdateReleaseExecutorTest extends TestCase
{
    /** @requirement UPD-001 RUN-001 RUN-006 QUA-001 */
    public function test_disk_capacity_failure_is_rejected_before_release_mutation(): void
    {
        $root = storage_path('framework/testing/update-executor-disk-'.bin2hex(random_bytes(4)));
        $php = $root.'/php';
        $composer = $root.'/composer';
        mkdir($root.'/releases', 0700, true);
        file_put_contents($php, "#!/bin/sh\nexit 0\n");
        file_put_contents($composer, "#!/bin/sh\nexit 0\n");
        chmod($php, 0700);
        chmod($composer, 0700);

        $id = new Process(['/usr/bin/id', '-un']);
        $id->mustRun();
        $runUser = trim($id->getOutput());

        $configuration = new UpdateRuntimeConfiguration(
            enabled: true,
            deploymentRoot: $root,
            packageRoot: $root,
            phpBinary: $php,
            composerBinary: $composer,
            runUser: $runUser,
            processTimeoutSeconds: 60,
            releaseRetention: 3,
        );
        $package = new VerifiedUpdatePackage(
            packagePath: $root.'/release.tar',
            packageSha256: str_repeat('a', 64),
            manifestSha256: str_repeat('b', 64),
            releaseId: '1.1.0',
            applicationVersion: '1.1.0',
            fromRelease: '1.0.0',
            fromApplicationVersion: '1.0.0',
            fromSchemaSha256: str_repeat('c', 64),
            toSchemaSha256: str_repeat('d', 64),
            rollbackCompatibleSchemaSha256: [],
            composerLockSha256: str_repeat('e', 64),
            laravelMajor: 13,
            phpMinimum: '8.4.0',
            phpMaximumExclusive: '9.0.0',
            requiredFreeBytes: PHP_INT_MAX,
            payloadBytes: 1,
            checksumsSha256: str_repeat('f', 64),
            payloadChecksums: ['artisan' => str_repeat('1', 64)],
        );

        try {
            (new ArtisanUpdateReleaseExecutor($configuration))->assertPrerequisites($package);
            self::fail('Insufficient disk capacity must fail before update mutation.');
        } catch (RuntimeException $exception) {
            self::assertSame('Update disk capacity is insufficient.', $exception->getMessage());
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement UPD-001 RUN-001 SEC-008 QUA-001 */
    public function test_missing_allowlisted_runtime_binary_is_rejected_before_subprocess_execution(): void
    {
        $root = storage_path('framework/testing/update-executor-binary-'.bin2hex(random_bytes(4)));
        mkdir($root.'/releases', 0700, true);

        $configuration = new UpdateRuntimeConfiguration(
            enabled: true,
            deploymentRoot: $root,
            packageRoot: $root,
            phpBinary: $root.'/missing-php',
            composerBinary: $root.'/missing-composer',
            runUser: 'www',
            processTimeoutSeconds: 60,
            releaseRetention: 3,
        );
        $package = new VerifiedUpdatePackage(
            packagePath: $root.'/release.tar',
            packageSha256: str_repeat('a', 64),
            manifestSha256: str_repeat('b', 64),
            releaseId: '1.1.0',
            applicationVersion: '1.1.0',
            fromRelease: '1.0.0',
            fromApplicationVersion: '1.0.0',
            fromSchemaSha256: str_repeat('c', 64),
            toSchemaSha256: str_repeat('d', 64),
            rollbackCompatibleSchemaSha256: [],
            composerLockSha256: str_repeat('e', 64),
            laravelMajor: 13,
            phpMinimum: '8.4.0',
            phpMaximumExclusive: '9.0.0',
            requiredFreeBytes: 1,
            payloadBytes: 1,
            checksumsSha256: str_repeat('f', 64),
            payloadChecksums: ['artisan' => str_repeat('1', 64)],
        );

        try {
            (new ArtisanUpdateReleaseExecutor($configuration))->assertPrerequisites($package);
            self::fail('Unavailable allowlisted runtime binaries must fail preflight.');
        } catch (RuntimeException $exception) {
            self::assertSame('An allowlisted update runtime binary is unavailable.', $exception->getMessage());
        } finally {
            $this->removeTree($root);
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

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
