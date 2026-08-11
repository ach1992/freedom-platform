<?php

declare(strict_types=1);

namespace App\Modules\Installer\Infrastructure;

use App\Modules\Installer\Application\Contracts\InstallerFinalizationRunner;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class InstallerArtisanProcessRunner implements InstallerFinalizationRunner
{
    /** @requirement INS-001 SEC-007 SEC-008 QUA-011 */
    public function __construct(
        private string $phpBinary,
        private string $artisanPath,
        private string $workingDirectory,
        private int $timeoutSeconds,
    ) {}

    public function clearConfiguration(): void
    {
        $this->run('config_clear', ['config:clear', '--no-ansi', '--no-interaction']);
    }

    public function migrate(): void
    {
        $this->run('migrations', ['migrate', '--force', '--no-ansi', '--no-interaction']);
    }

    public function cacheConfiguration(): void
    {
        $this->run('config_cache', ['config:cache', '--no-ansi', '--no-interaction']);
    }

    /** @param  list<string>  $arguments */
    private function run(string $step, array $arguments): void
    {
        $this->validateRuntime();

        $process = new Process(
            [$this->phpBinary, $this->artisanPath, ...$arguments],
            $this->workingDirectory,
            null,
            null,
            max(1, $this->timeoutSeconds),
        );
        $process->setIdleTimeout(max(1, $this->timeoutSeconds));

        try {
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException('The installer finalization process could not complete the '.$step.' step.');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The installer finalization process failed the '.$step.' step.');
        }
    }

    private function validateRuntime(): void
    {
        if (! str_starts_with($this->phpBinary, DIRECTORY_SEPARATOR)
            || ! is_file($this->phpBinary)
            || ! is_executable($this->phpBinary)
        ) {
            throw new RuntimeException('The installer finalization PHP runtime is unavailable.');
        }

        if (! str_starts_with($this->artisanPath, DIRECTORY_SEPARATOR)
            || ! is_file($this->artisanPath)
            || ! is_readable($this->artisanPath)
        ) {
            throw new RuntimeException('The installer finalization entry point is unavailable.');
        }

        if (! str_starts_with($this->workingDirectory, DIRECTORY_SEPARATOR) || ! is_dir($this->workingDirectory)) {
            throw new RuntimeException('The installer finalization working directory is unavailable.');
        }
    }
}
