<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\Contracts\InstallerFinalizationRunner;
use App\Modules\Installer\Application\InstallerBootstrapJournal;
use App\Modules\Installer\Application\InstallerBootstrapOrchestrator;
use App\Modules\Installer\Application\InstallerEnvironmentBootstrapper;
use App\Modules\Installer\Application\InstallerEnvironmentWriter;
use App\Modules\Installer\Application\InstallerFinalizer;
use App\Modules\Installer\Application\InstallerLock;
use App\Modules\Installer\Application\InstallerProductionEnvironmentPolicy;
use App\Shared\Application\RandomGenerator;
use RuntimeException;
use Tests\TestCase;

final class InstallerFinalizerTest extends TestCase
{
    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_finalizes_in_fixed_order_and_locks_only_after_cache_success(): void
    {
        $paths = $this->paths('success');
        $runner = new RecordingFinalizationRunner;
        [$finalizer, $lock] = $this->finalizer($paths, $runner);

        try {
            $lifecyclePassword = 'test-only-lifecycle-finalizer-password';
            $result = $finalizer->finalize(['DB_HOST' => 'database.internal'], $lifecyclePassword);

            $this->assertSame('completed', $result['status']);
            $this->assertSame(
                ['config_clear', 'migrations', 'config_cache'],
                $runner->actions,
            );
            $this->assertSame($lifecyclePassword, $runner->migrationPassword);
            $this->assertTrue($lock->exists());
            $this->assertFileExists($paths['environment']);
            $this->assertFileDoesNotExist($paths['snapshot']);
        } finally {
            $this->cleanup($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_unsafe_production_environment_is_rejected_before_environment_or_finalization_side_effects(): void
    {
        $paths = $this->paths('unsafe-production');
        $runner = new RecordingFinalizationRunner;
        [$finalizer, $lock] = $this->finalizer(
            $paths,
            $runner,
            'production',
            ['APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE'],
        );

        try {
            try {
                $finalizer->finalize([
                    'APP_ENV' => 'production',
                    'APP_DEBUG' => 'true',
                    'APP_URL' => 'https://example.test',
                    'SESSION_ENCRYPT' => 'true',
                    'SESSION_SECURE_COOKIE' => 'true',
                ]);
                $this->fail('Unsafe production environment semantics must abort finalization.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment is not safe to finalize.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame([], $runner->actions);
            $this->assertFalse($lock->exists());
            $this->assertFileDoesNotExist($paths['environment']);
            $this->assertFileDoesNotExist($paths['snapshot']);
            $this->assertFileDoesNotExist($paths['snapshot'].'.json');
        } finally {
            $this->cleanup($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_interpolated_production_value_is_rejected_before_environment_or_finalization_side_effects(): void
    {
        $paths = $this->paths('interpolated-production');
        $runner = new RecordingFinalizationRunner;
        $original = implode("\n", [
            'APP_KEY="base64:existing-test-key"',
            'INSTALLER_ORIGIN="https://safe.example"',
            'APP_ENV="production"',
            'APP_DEBUG="false"',
            'APP_URL="${INSTALLER_ORIGIN}"',
            'SESSION_ENCRYPT="true"',
            'SESSION_SECURE_COOKIE="true"',
            '',
        ]);
        $this->writeFixture($paths['environment'], $original);
        [$finalizer, $lock] = $this->finalizer(
            $paths,
            $runner,
            'production',
            ['APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE'],
        );
        $previousOrigin = getenv('INSTALLER_ORIGIN');
        putenv('INSTALLER_ORIGIN=http://unsafe.example');

        try {
            try {
                $finalizer->finalize([]);
                $this->fail('Interpolated production security values must abort finalization.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment must use literal security-critical values.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame([], $runner->actions);
            $this->assertFalse($lock->exists());
            $this->assertSame($original, file_get_contents($paths['environment']));
            $this->assertFileDoesNotExist($paths['snapshot']);
            $this->assertFileDoesNotExist($paths['snapshot'].'.json');
        } finally {
            if ($previousOrigin === false) {
                putenv('INSTALLER_ORIGIN');
            } else {
                putenv('INSTALLER_ORIGIN='.$previousOrigin);
            }

            $this->cleanup($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_interpolated_app_env_is_rejected_before_non_production_classification_or_side_effects(): void
    {
        $paths = $this->paths('interpolated-app-env-classification');
        $runner = new RecordingFinalizationRunner;
        $original = implode("\n", [
            'APP_KEY="base64:existing-test-key"',
            'TARGET_ENV="local"',
            'APP_ENV="${TARGET_ENV}"',
            'APP_DEBUG="true"',
            'APP_URL="http://localhost:8000"',
            'SESSION_ENCRYPT="false"',
            'SESSION_SECURE_COOKIE="false"',
            '',
        ]);
        $this->writeFixture($paths['environment'], $original);
        [$finalizer, $lock] = $this->finalizer(
            $paths,
            $runner,
            'testing',
            ['APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE'],
        );
        $previousTarget = getenv('TARGET_ENV');
        putenv('TARGET_ENV=production');

        try {
            try {
                $finalizer->finalize([]);
                $this->fail('Interpolated APP_ENV must be rejected before target classification.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment must use literal security-critical values.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame([], $runner->actions);
            $this->assertFalse($lock->exists());
            $this->assertSame($original, file_get_contents($paths['environment']));
            $this->assertFileDoesNotExist($paths['snapshot']);
            $this->assertFileDoesNotExist($paths['snapshot'].'.json');
        } finally {
            if ($previousTarget === false) {
                putenv('TARGET_ENV');
            } else {
                putenv('TARGET_ENV='.$previousTarget);
            }

            $this->cleanup($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_migration_failure_restores_environment_and_prevents_lock_activation(): void
    {
        $paths = $this->paths('migration-failure');
        $original = "APP_KEY=base64:existing-test-key\nDB_HOST=old-host\n";
        $this->writeFixture($paths['environment'], $original);
        $runner = new RecordingFinalizationRunner('migrations');
        [$finalizer, $lock] = $this->finalizer($paths, $runner);

        try {
            try {
                $finalizer->finalize(['DB_HOST' => 'new-host']);
                $this->fail('A migration failure must abort finalization.');
            } catch (RuntimeException $exception) {
                $this->assertSame('test-only migrations failure', $exception->getMessage());
            }

            $this->assertSame(['config_clear', 'migrations'], $runner->actions);
            $this->assertSame($original, file_get_contents($paths['environment']));
            $this->assertFalse($lock->exists());
            $this->assertFileDoesNotExist($paths['snapshot']);
        } finally {
            $this->cleanup($paths);
        }
    }

    /**
     * @param  array{environment: string, snapshot: string, journal: string, lock: string}  $paths
     * @return array{InstallerFinalizer, InstallerLock}
     */
    private function finalizer(
        array $paths,
        InstallerFinalizationRunner $runner,
        string $runtimeEnvironment = 'testing',
        array $allowedKeys = ['APP_KEY', 'DB_HOST'],
    ): array {
        $journal = new InstallerBootstrapJournal($paths['journal']);
        $lock = new InstallerLock($paths['lock']);
        $writer = new InstallerEnvironmentWriter(
            new InstallerProductionEnvironmentPolicy($runtimeEnvironment),
            new class implements RandomGenerator
            {
                public function bytes(int $length): string
                {
                    return str_repeat("\x04", $length);
                }

                public function integer(int $minimum, int $maximum): int
                {
                    return $minimum;
                }
            },
            $paths['environment'],
            $paths['snapshot'],
            $allowedKeys,
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD'],
        );
        $bootstrapper = new InstallerEnvironmentBootstrapper(
            $writer,
            new InstallerBootstrapOrchestrator($journal, $lock),
            $journal,
        );

        return [new InstallerFinalizer($bootstrapper, $runner), $lock];
    }

    /** @return array{directory: string, environment: string, snapshot: string, journal: string, lock: string} */
    private function paths(string $case): array
    {
        $directory = storage_path('framework/testing/installer-finalizer-'.$case.'-'.bin2hex(random_bytes(4)));

        return [
            'directory' => $directory,
            'environment' => $directory.'/.env',
            'snapshot' => $directory.'/private/environment.snapshot',
            'journal' => $directory.'/private/bootstrap-journal.json',
            'lock' => $directory.'/private/installer.lock',
        ];
    }

    private function writeFixture(string $path, string $contents): void
    {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            $this->fail('Could not create finalizer fixture directory.');
        }

        file_put_contents($path, $contents);
    }

    /** @param  array{directory: string, environment: string, snapshot: string, journal: string, lock: string}  $paths */
    private function cleanup(array $paths): void
    {
        foreach ([
            $paths['snapshot'].'.json',
            $paths['snapshot'].'.lock',
            $paths['snapshot'],
            $paths['journal'],
            $paths['lock'],
            $paths['environment'],
        ] as $path) {
            @unlink($path);
        }

        @rmdir($paths['directory'].'/private');
        @rmdir($paths['directory']);
    }
}

final class RecordingFinalizationRunner implements InstallerFinalizationRunner
{
    /** @var list<string> */
    public array $actions = [];

    public ?string $migrationPassword = null;

    public function __construct(private readonly ?string $failAt = null) {}

    public function clearConfiguration(): void
    {
        $this->record('config_clear');
    }

    public function migrate(?string $lifecycleDatabasePassword = null): void
    {
        $this->migrationPassword = $lifecycleDatabasePassword;
        $this->record('migrations');
    }

    public function cacheConfiguration(): void
    {
        $this->record('config_cache');
    }

    private function record(string $action): void
    {
        $this->actions[] = $action;

        if ($this->failAt === $action) {
            throw new RuntimeException('test-only '.$action.' failure');
        }
    }
}
