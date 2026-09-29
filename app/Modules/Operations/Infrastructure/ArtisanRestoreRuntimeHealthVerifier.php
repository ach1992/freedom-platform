<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreRuntimeHealthVerifier;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class ArtisanRestoreRuntimeHealthVerifier implements RestoreRuntimeHealthVerifier
{
    public function __construct(
        private string $phpBinary,
        private string $artisanPath,
        private string $workingDirectory,
        private int $timeoutSeconds,
    ) {}

    /** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
    public function verify(string $expectedAuthorityFingerprint): void
    {
        $this->assertRuntime();
        if (preg_match('/\\A[0-9a-f]{64}\\z/', $expectedAuthorityFingerprint) !== 1) {
            throw new RuntimeException('The expected Restore authority fingerprint is invalid.');
        }

        $process = new Process(
            [
                $this->phpBinary,
                $this->artisanPath,
                'operations:restore-runtime-attest',
                '--expected-authority-fingerprint='.$expectedAuthorityFingerprint,
                '--json',
                '--no-ansi',
                '--no-interaction',
            ],
            $this->workingDirectory,
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
            null,
            max(1, $this->timeoutSeconds),
        );
        $process->setIdleTimeout(max(1, $this->timeoutSeconds));

        try {
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException('The restored runtime health process could not complete.');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The restored runtime health checks failed.');
        }
    }

    private function assertRuntime(): void
    {
        if (! str_starts_with($this->phpBinary, DIRECTORY_SEPARATOR)
            || ! is_file($this->phpBinary)
            || ! is_executable($this->phpBinary)
        ) {
            throw new RuntimeException('The restore health PHP runtime is unavailable.');
        }

        if (! str_starts_with($this->artisanPath, DIRECTORY_SEPARATOR)
            || ! is_file($this->artisanPath)
            || is_link($this->artisanPath)
            || ! is_readable($this->artisanPath)
        ) {
            throw new RuntimeException('The restore health entry point is unavailable.');
        }

        if (! str_starts_with($this->workingDirectory, DIRECTORY_SEPARATOR)
            || ! is_dir($this->workingDirectory)
            || is_link($this->workingDirectory)
            || $this->timeoutSeconds < 1
        ) {
            throw new RuntimeException('The restore health runtime configuration is invalid.');
        }
    }
}
