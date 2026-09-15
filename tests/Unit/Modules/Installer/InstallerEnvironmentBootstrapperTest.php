<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerBootstrapJournal;
use App\Modules\Installer\Application\InstallerBootstrapOrchestrator;
use App\Modules\Installer\Application\InstallerEnvironmentBootstrapper;
use App\Modules\Installer\Application\InstallerEnvironmentWriter;
use App\Modules\Installer\Application\InstallerLock;
use App\Shared\Application\RandomGenerator;
use RuntimeException;
use Tests\TestCase;

final class InstallerEnvironmentBootstrapperTest extends TestCase
{
    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_successful_bootstrap_keeps_the_environment_removes_snapshot_and_locks_installer(): void
    {
        $paths = $this->paths('success');
        $writer = $this->writer($paths);
        $journal = new InstallerBootstrapJournal($paths['journal']);
        $lock = new InstallerLock($paths['lock']);
        $service = new InstallerEnvironmentBootstrapper(
            $writer,
            new InstallerBootstrapOrchestrator($journal, $lock),
            $journal,
        );
        $downstreamRan = false;

        try {
            $result = $service->run(
                ['DB_HOST' => 'database.internal'],
                [
                    'migrations' => function () use (&$downstreamRan): void {
                        $downstreamRan = true;
                    },
                ],
            );

            $this->assertSame('completed', $result['status']);
            $this->assertTrue($downstreamRan);
            $this->assertFileExists($paths['environment']);
            $this->assertTrue($lock->exists());
            $this->assertFileDoesNotExist($paths['snapshot']);
            $this->assertFileDoesNotExist($paths['snapshot'].'.json');
        } finally {
            $this->cleanup($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_downstream_failure_restores_existing_environment_and_forces_environment_retry(): void
    {
        $paths = $this->paths('existing-failure');
        $original = "APP_KEY=base64:existing-test-key\nDB_HOST=old-host\n";
        $this->writeFixture($paths['environment'], $original);
        $writer = $this->writer($paths);
        $journal = new InstallerBootstrapJournal($paths['journal']);
        $lock = new InstallerLock($paths['lock']);
        $service = new InstallerEnvironmentBootstrapper(
            $writer,
            new InstallerBootstrapOrchestrator($journal, $lock),
            $journal,
        );

        try {
            try {
                $service->run(
                    ['DB_HOST' => 'new-host'],
                    [
                        'migrations' => static function (): void {
                            throw new RuntimeException('test-only downstream failure');
                        },
                    ],
                );
                $this->fail('A downstream bootstrap failure must be rethrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('test-only downstream failure', $exception->getMessage());
            }

            $this->assertSame($original, file_get_contents($paths['environment']));
            $this->assertFalse($lock->exists());
            $this->assertSame('environment', $journal->current()['step']);
            $this->assertSame('failed', $journal->current()['status']);
            $this->assertFileDoesNotExist($paths['snapshot']);
        } finally {
            $this->cleanup($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_downstream_failure_removes_a_new_environment_file(): void
    {
        $paths = $this->paths('new-failure');
        $writer = $this->writer($paths);
        $journal = new InstallerBootstrapJournal($paths['journal']);
        $lock = new InstallerLock($paths['lock']);
        $service = new InstallerEnvironmentBootstrapper(
            $writer,
            new InstallerBootstrapOrchestrator($journal, $lock),
            $journal,
        );

        try {
            try {
                $service->run(
                    ['DB_HOST' => 'new-host'],
                    [
                        'migrations' => static function (): void {
                            throw new RuntimeException('test-only migration failure');
                        },
                    ],
                );
                $this->fail('A downstream bootstrap failure must be rethrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('test-only migration failure', $exception->getMessage());
            }

            $this->assertFileDoesNotExist($paths['environment']);
            $this->assertFalse($lock->exists());
            $this->assertSame('environment', $journal->current()['step']);
            $this->assertSame('failed', $journal->current()['status']);
        } finally {
            $this->cleanup($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_existing_lock_is_a_no_op_and_does_not_change_environment(): void
    {
        $paths = $this->paths('locked');
        $original = "APP_KEY=base64:existing-test-key\nDB_HOST=old-host\n";
        $this->writeFixture($paths['environment'], $original);
        $writer = $this->writer($paths);
        $journal = new InstallerBootstrapJournal($paths['journal']);
        $lock = new InstallerLock($paths['lock']);
        $lock->activate();
        $service = new InstallerEnvironmentBootstrapper(
            $writer,
            new InstallerBootstrapOrchestrator($journal, $lock),
            $journal,
        );

        try {
            $result = $service->run(
                ['DB_HOST' => 'must-not-be-written'],
                ['migrations' => static function (): void {
                    throw new RuntimeException('must not execute');
                }],
            );

            $this->assertSame('already_locked', $result['status']);
            $this->assertSame($original, file_get_contents($paths['environment']));
            $this->assertNull($journal->current());
        } finally {
            $this->cleanup($paths);
        }
    }

    /** @return array{directory: string, environment: string, snapshot: string, journal: string, lock: string} */
    private function paths(string $case): array
    {
        $directory = storage_path('framework/testing/installer-environment-bootstrap-'.$case.'-'.bin2hex(random_bytes(4)));

        return [
            'directory' => $directory,
            'environment' => $directory.'/.env',
            'snapshot' => $directory.'/private/environment.snapshot',
            'journal' => $directory.'/private/bootstrap-journal.json',
            'lock' => $directory.'/private/installer.lock',
        ];
    }

    /** @param  array{environment: string, snapshot: string}  $paths */
    private function writer(array $paths): InstallerEnvironmentWriter
    {
        return new InstallerEnvironmentWriter(
            new class implements RandomGenerator
            {
                public function bytes(int $length): string
                {
                    return str_repeat("\x03", $length);
                }

                public function integer(int $minimum, int $maximum): int
                {
                    return $minimum;
                }
            },
            $paths['environment'],
            $paths['snapshot'],
            ['APP_KEY', 'DB_HOST'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD'],
        );
    }

    private function writeFixture(string $path, string $contents): void
    {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            $this->fail('Could not create bootstrap fixture directory.');
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
