<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use RuntimeException;

final class InstallerBootstrapJournal
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function __construct(private readonly string $path) {}

    /** @return array{step: string, status: string, updated_at: string}|null */
    public function current(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if (! is_string($contents)) {
            return null;
        }

        $data = json_decode($contents, true);

        if (! is_array($data)
            || ! is_string($data['step'] ?? null)
            || ! is_string($data['status'] ?? null)
            || ! is_string($data['updated_at'] ?? null)
        ) {
            return null;
        }

        return [
            'step' => $data['step'],
            'status' => $data['status'],
            'updated_at' => $data['updated_at'],
        ];
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
            if (file_put_contents($temporary, json_encode([
                'step' => $step,
                'status' => $status,
                'updated_at' => gmdate(DATE_ATOM),
            ], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Could not write bootstrap journal file.');
            }

            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Could not secure bootstrap journal permissions.');
            }

            if (! rename($temporary, $this->path)) {
                throw new RuntimeException('Could not activate bootstrap journal file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
