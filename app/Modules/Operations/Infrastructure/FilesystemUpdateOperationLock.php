<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\UpdateOperationLock;
use Closure;
use RuntimeException;

final readonly class FilesystemUpdateOperationLock implements UpdateOperationLock
{
    public function __construct(private string $deploymentRoot) {}

    public function synchronized(Closure $operation): mixed
    {
        $root = realpath($this->deploymentRoot);
        if ($root === false || ! is_dir($root) || is_link($this->deploymentRoot)) {
            throw new RuntimeException('Update operation lock deployment root is unsafe.');
        }

        $shared = $root.'/shared';
        if (! is_dir($shared) || is_link($shared)) {
            throw new RuntimeException('Update operation lock shared authority is unsafe.');
        }

        $path = $shared.'/update-operation.lock';
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('Update operation lock path is unsafe.');
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false || ! chmod($path, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new RuntimeException('Update operation lock could not be opened securely.');
        }

        $acquired = false;

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another controlled update operation is already active.');
            }
            $acquired = true;

            return $operation();
        } finally {
            if ($acquired) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }
}
