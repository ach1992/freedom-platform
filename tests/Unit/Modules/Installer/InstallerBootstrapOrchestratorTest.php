<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerBootstrapJournal;
use App\Modules\Installer\Application\InstallerBootstrapOrchestrator;
use App\Modules\Installer\Application\InstallerLock;
use RuntimeException;
use Tests\TestCase;

final class InstallerBootstrapOrchestratorTest extends TestCase
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function test_it_runs_steps_in_order_completes_the_journal_and_activates_the_lock(): void
    {
        [$journalPath, $lockPath] = $this->paths('success');
        $executed = [];

        try {
            $journal = new InstallerBootstrapJournal($journalPath);
            $lock = new InstallerLock($lockPath);
            $orchestrator = new InstallerBootstrapOrchestrator($journal, $lock);

            $result = $orchestrator->run([
                'environment' => function () use (&$executed): void {
                    $executed[] = 'environment';
                },
                'migrations' => function () use (&$executed): void {
                    $executed[] = 'migrations';
                },
            ]);

            $this->assertSame(['environment', 'migrations'], $executed);
            $this->assertSame([
                'status' => 'completed',
                'completed_steps' => ['environment', 'migrations'],
                'resumed' => false,
            ], $result);
            $this->assertSame('bootstrap', $journal->current()['step']);
            $this->assertSame('completed', $journal->current()['status']);
            $this->assertTrue($lock->exists());
        } finally {
            $this->cleanup($journalPath, $lockPath);
        }
    }

    /** @requirement INS-001 SEC-007 QUA-011 */
    public function test_it_skips_all_steps_when_the_installer_is_already_locked(): void
    {
        [$journalPath, $lockPath] = $this->paths('locked');
        $executed = false;

        try {
            $journal = new InstallerBootstrapJournal($journalPath);
            $lock = new InstallerLock($lockPath);
            $lock->activate();
            $orchestrator = new InstallerBootstrapOrchestrator($journal, $lock);

            $result = $orchestrator->run([
                'environment' => function () use (&$executed): void {
                    $executed = true;
                },
            ]);

            $this->assertFalse($executed);
            $this->assertSame([
                'status' => 'already_locked',
                'completed_steps' => [],
                'resumed' => false,
            ], $result);
            $this->assertNull($journal->current());
        } finally {
            $this->cleanup($journalPath, $lockPath);
        }
    }

    /** @requirement INS-001 SEC-007 QUA-011 */
    public function test_it_records_failure_and_retries_the_failed_step_without_locking(): void
    {
        [$journalPath, $lockPath] = $this->paths('failure');
        $attempts = 0;

        try {
            $journal = new InstallerBootstrapJournal($journalPath);
            $lock = new InstallerLock($lockPath);
            $orchestrator = new InstallerBootstrapOrchestrator($journal, $lock);

            try {
                $orchestrator->run([
                    'environment' => function () use (&$attempts): void {
                        $attempts++;
                        throw new RuntimeException('simulated failure');
                    },
                ]);

                $this->fail('The bootstrap failure must be rethrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('simulated failure', $exception->getMessage());
            }

            $this->assertSame(1, $attempts);
            $this->assertSame('environment', $journal->current()['step']);
            $this->assertSame('failed', $journal->current()['status']);
            $this->assertFalse($lock->exists());

            $result = $orchestrator->run([
                'environment' => function () use (&$attempts): void {
                    $attempts++;
                },
            ]);

            $this->assertSame(2, $attempts);
            $this->assertTrue($result['resumed']);
            $this->assertTrue($lock->exists());
        } finally {
            $this->cleanup($journalPath, $lockPath);
        }
    }

    /** @requirement INS-001 SEC-007 QUA-011 */
    public function test_it_resumes_after_the_last_completed_step(): void
    {
        [$journalPath, $lockPath] = $this->paths('resume');
        $executed = [];

        try {
            $journal = new InstallerBootstrapJournal($journalPath);
            $journal->record('environment', 'completed');
            $lock = new InstallerLock($lockPath);
            $orchestrator = new InstallerBootstrapOrchestrator($journal, $lock);

            $result = $orchestrator->run([
                'environment' => function () use (&$executed): void {
                    $executed[] = 'environment';
                },
                'migrations' => function () use (&$executed): void {
                    $executed[] = 'migrations';
                },
            ]);

            $this->assertSame(['migrations'], $executed);
            $this->assertTrue($result['resumed']);
            $this->assertTrue($lock->exists());
        } finally {
            $this->cleanup($journalPath, $lockPath);
        }
    }

    /** @return array{string, string} */
    private function paths(string $case): array
    {
        $directory = storage_path('framework/testing/installer-bootstrap-'.$case.'-'.bin2hex(random_bytes(4)));

        return [$directory.'/journal.json', $directory.'/lock.json'];
    }

    private function cleanup(string ...$paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }

        if ($paths !== []) {
            @rmdir(dirname($paths[0]));
        }
    }
}
