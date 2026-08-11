<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\ReleaseHealthVerifier;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class ArtisanReleaseHealthVerifier implements ReleaseHealthVerifier
{
    /** @requirement RUN-001 RUN-002 RUN-003 SEC-008 SEC-010 QUA-011 */
    public function __construct(
        private string $phpBinary,
        private int $timeoutSeconds,
    ) {}

    public function verify(string $releasePath): void
    {
        $artisan = $releasePath.'/artisan';

        if (! str_starts_with($this->phpBinary, DIRECTORY_SEPARATOR)
            || ! is_file($this->phpBinary)
            || ! is_executable($this->phpBinary)
        ) {
            throw new RuntimeException('The release health PHP runtime is unavailable.');
        }

        if (! str_starts_with($releasePath, DIRECTORY_SEPARATOR)
            || ! is_dir($releasePath)
            || ! is_file($artisan)
            || is_link($artisan)
            || ! is_readable($artisan)
        ) {
            throw new RuntimeException('The release health entry point is unavailable.');
        }

        $process = new Process(
            [
                $this->phpBinary,
                $artisan,
                'health:check',
                '--critical',
                '--json',
                '--redact',
                '--no-ansi',
                '--no-interaction',
            ],
            $releasePath,
            null,
            null,
            max(1, $this->timeoutSeconds),
        );
        $process->setIdleTimeout(max(1, $this->timeoutSeconds));

        try {
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException('The activated release health verification could not complete.');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The activated release failed health verification.');
        }
    }
}
