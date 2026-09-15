<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use RuntimeException;

final class InstallerLock
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function activate(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create installer lock directory.');
        }

        $temporary = tempnam($directory, 'lock-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create installer lock file.');
        }

        try {
            if (file_put_contents($temporary, json_encode([
                'locked_at' => gmdate(DATE_ATOM),
            ], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Could not write installer lock file.');
            }

            chmod($temporary, 0600);

            if (! rename($temporary, $this->path)) {
                throw new RuntimeException('Could not activate installer lock.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
