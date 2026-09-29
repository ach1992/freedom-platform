<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\FilesystemRestoreSchedulerMutationLock;
use RuntimeException;
use Tests\TestCase;

final class FilesystemRestoreSchedulerMutationLockTest extends TestCase
{
    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_second_restore_fails_closed_while_scheduler_mutation_fence_is_owned(): void
    {
        $directory = $this->directory('contention');
        $path = $directory.'/scheduler.lock';

        try {
            $first = new FilesystemRestoreSchedulerMutationLock($path);
            $second = new FilesystemRestoreSchedulerMutationLock($path);

            $first->acquire(1);

            try {
                $second->acquire(1);
                self::fail('A second owner must not cross an active Scheduler mutation fence.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Restore Scheduler mutation did not quiesce within the configured bound.',
                    $exception->getMessage(),
                );
            }

            self::assertSame(0600, fileperms($path) & 0777);

            $first->release();
            $second->acquire(1);
            $second->release();
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function test_symlink_lock_authority_is_rejected(): void
    {
        $directory = $this->directory('symlink');
        $real = $directory.'/real.lock';
        $path = $directory.'/scheduler.lock';
        file_put_contents($real, '');
        symlink($real, $path);

        try {
            try {
                (new FilesystemRestoreSchedulerMutationLock($path))->acquire(1);
                self::fail('Scheduler mutation lock symlink must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame('Restore Scheduler mutation lock authority is unsafe.', $exception->getMessage());
            }
        } finally {
            @unlink($path);
            @unlink($real);
            @rmdir($directory);
        }
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/restore-scheduler-lock-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($path, 0700, true);

        return $path;
    }
}
