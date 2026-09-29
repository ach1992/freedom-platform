<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestorePayloadFilesystem;
use RuntimeException;

final readonly class NativeRestorePayloadFilesystem implements RestorePayloadFilesystem
{
    public function move(string $source, string $destination): bool
    {
        return rename($source, $destination);
    }

    public function remove(string $path): void
    {
        $this->removeTree($path);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('A restore recovery path contains an unsafe symbolic link.');
        }

        if (is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('A restore recovery file could not be removed.');
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.'/'.$name);
            }
        }

        if (! rmdir($path)) {
            throw new RuntimeException('A restore recovery directory could not be removed.');
        }
    }
}
