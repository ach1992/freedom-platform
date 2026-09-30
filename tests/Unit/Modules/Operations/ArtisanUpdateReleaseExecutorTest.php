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

    /** @requirement UPD-001 RUN-001 RUN-006 SEC-008 QUA-001 */
    public function test_dependency_install_suppresses_plugins_and_scripts_and_runtime_bootstrap_requires_shared_links(): void
    {
        $root = storage_path('framework/testing/update-executor-order-'.bin2hex(random_bytes(4)));
        $release = $root.'/releases/1.1.0';
        $php = $root.'/php';
        $composer = $root.'/composer';
        $log = $root.'/commands.log';
        mkdir($release, 0700, true);
        mkdir($root.'/shared/storage', 0700, true);
        file_put_contents($root.'/shared/.env', "APP_ENV=testing\n");
        file_put_contents($release.'/artisan', "<?php\n");
        file_put_contents($release.'/composer.json', "{}\n");
        file_put_contents($release.'/composer.lock', "{\"packages\":[]}\n");

        $logger = static fn (string $path): string => "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($path)."\nexit 0\n";
        file_put_contents($php, $logger($log));
        file_put_contents($composer, $logger($log));
        chmod($php, 0700);
        chmod($composer, 0700);

        $lockSha256 = hash_file('sha256', $release.'/composer.lock');
        self::assertIsString($lockSha256);

        $configuration = new UpdateRuntimeConfiguration(
            enabled: true,
            deploymentRoot: $root,
            packageRoot: $root,
            phpBinary: $php,
            composerBinary: $composer,
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
            composerLockSha256: $lockSha256,
            laravelMajor: 13,
            phpMinimum: '8.4.0',
            phpMaximumExclusive: '9.0.0',
            requiredFreeBytes: 1,
            payloadBytes: 1,
            checksumsSha256: str_repeat('f', 64),
            payloadChecksums: ['artisan' => str_repeat('1', 64)],
        );
        $executor = new ArtisanUpdateReleaseExecutor($configuration);

        try {
            $executor->prepare($release, $package);
            $beforeLinks = (string) file_get_contents($log);
            self::assertStringContainsString(
                '--no-plugins install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction --no-progress',
                $beforeLinks,
            );
            self::assertStringContainsString('--no-plugins validate --strict --no-check-publish --no-interaction', $beforeLinks);
            self::assertStringContainsString('--no-plugins check-platform-reqs --no-dev --no-interaction', $beforeLinks);
            self::assertStringNotContainsString('package:discover', $beforeLinks);

            try {
                $executor->prepareRuntime($release);
                self::fail('Laravel runtime bootstrap must wait for approved shared links.');
            } catch (RuntimeException $exception) {
                self::assertSame('The exact-release shared runtime links are not prepared.', $exception->getMessage());
            }

            symlink('../../shared/.env', $release.'/.env');
            symlink('../../shared/storage', $release.'/storage');
            $executor->prepareRuntime($release);

            $afterLinks = (string) file_get_contents($log);
            self::assertStringContainsString(
                $release.'/artisan package:discover --ansi --no-interaction',
                $afterLinks,
            );
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
