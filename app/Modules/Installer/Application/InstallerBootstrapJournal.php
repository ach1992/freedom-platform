<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use RuntimeException;

final class InstallerBootstrapJournal
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function __construct(private readonly string $path)
    {
    }

    /** @return array{step: string, status: string, updated_at: string}|null */
    public function current(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        return is_array($data) ? $data : null;
    }

    public function record(string $step, string $status): void
    {
        if ($step === '' || $status === '') {
            throw new RuntimeException('Bootstrap journal values cannot be empty.');
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create bootstrap journal directory.');
        }

        $temporary = tempnam($directory, 'bootstrap-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create bootstrap journal file.');
        }

        try {
            file_put_contents($temporary, json_encode([
                'step' => $step,
                'status' => $status,
                'updated_at' => gmdate(DATE_ATOM),
            ], JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($temporary, 0600);
            rename($temporary, $this->path);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
