<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreSchedulerMutationLock;
use RuntimeException;

final class FilesystemRestoreSchedulerMutationLock implements RestoreSchedulerMutationLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || str_contains($path, "\0")) {
            throw new RuntimeException('Restore Scheduler mutation lock path is invalid.');
        }
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function acquire(int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 3600 || is_resource($this->handle)) {
            throw new RuntimeException('Restore Scheduler mutation lock acquisition is invalid.');
        }

        $parent = realpath(dirname($this->path));
        if ($parent === false || ! is_dir($parent) || is_link(dirname($this->path))) {
            throw new RuntimeException('Restore Scheduler mutation lock authority is unavailable.');
        }

        $path = $parent.'/'.basename($this->path);
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('Restore Scheduler mutation lock authority is unsafe.');
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false || ! chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new RuntimeException('Restore Scheduler mutation lock could not be opened securely.');
        }

        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;

                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        throw new RuntimeException('Restore Scheduler mutation did not quiesce within the configured bound.');
    }

    public function held(): bool
    {
        return is_resource($this->handle);
    }

    /** @requirement BAK-002 OPS-003 QUA-001 */
    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        $handle = $this->handle;
        if (! flock($handle, LOCK_UN)) {
            throw new RuntimeException('Restore Scheduler mutation lock could not be released.');
        }

        fclose($handle);
        $this->handle = null;
    }
}
