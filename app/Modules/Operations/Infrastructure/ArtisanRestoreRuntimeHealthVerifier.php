<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreRuntimeHealthVerifier;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class ArtisanRestoreRuntimeHealthVerifier implements RestoreRuntimeHealthVerifier
{
    /** @var list<string> */
    private const PROCESS_ENVIRONMENT_ALLOWLIST = [
        'PATH',
        'HOME',
        'TMPDIR',
        'TMP',
        'TEMP',
        'LANG',
        'LC_ALL',
        'LC_CTYPE',
        'TZ',
        'SYSTEMROOT',
        'SystemRoot',
        'WINDIR',
        'COMSPEC',
        'PATHEXT',
    ];

    public function __construct(
        private string $phpBinary,
        private string $artisanPath,
        private string $workingDirectory,
        private int $timeoutSeconds,
    ) {}

    /** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
    public function verify(string $expectedAuthorityFingerprint, ?string $releasePath = null): void
    {
        $artisanPath = $artisanPath;
        $workingDirectory = $workingDirectory;
        if ($releasePath !== null) {
            if (! str_starts_with($releasePath, DIRECTORY_SEPARATOR)
                || is_link($releasePath)
                || ($resolvedRelease = realpath($releasePath)) === false
                || ! is_dir($resolvedRelease)
            ) {
                throw new RuntimeException('The exact restored release runtime is unavailable.');
            }
            $artisanPath = $resolvedRelease.'/artisan';
            $workingDirectory = $resolvedRelease;
        }

        $this->assertRuntime($artisanPath, $workingDirectory);
        if (preg_match('/\\A[0-9a-f]{64}\\z/', $expectedAuthorityFingerprint) !== 1) {
            throw new RuntimeException('The expected Restore authority fingerprint is invalid.');
        }

        $process = new Process(
            [
                $this->phpBinary,
                $artisanPath,
                'operations:restore-runtime-attest',
                '--expected-authority-fingerprint='.$expectedAuthorityFingerprint,
                '--json',
                '--no-ansi',
                '--no-interaction',
            ],
            $workingDirectory,
            $this->sanitizedEnvironment(),
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

    /**
     * Symfony Process merges explicit overrides with the parent process
     * environment. Explicitly mask every inherited variable except a minimal
     * operating-system allowlist so Laravel must load application authority
     * from the restored environment file.
     *
     * @return array<string, string|false>
     */
    private function sanitizedEnvironment(): array
    {
        $environment = [];

        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach (array_keys($source) as $name) {
                if (is_string($name) && $name !== '') {
                    $environment[$name] = false;
                }
            }
        }

        foreach (self::PROCESS_ENVIRONMENT_ALLOWLIST as $name) {
            $value = getenv($name);

            if ($value === false) {
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
            }

            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function assertRuntime(string $artisanPath, string $workingDirectory): void
    {
        if (! str_starts_with($this->phpBinary, DIRECTORY_SEPARATOR)
            || ! is_file($this->phpBinary)
            || ! is_executable($this->phpBinary)
        ) {
            throw new RuntimeException('The restore health PHP runtime is unavailable.');
        }

        if (! str_starts_with($artisanPath, DIRECTORY_SEPARATOR)
            || ! is_file($artisanPath)
            || is_link($artisanPath)
            || ! is_readable($artisanPath)
        ) {
            throw new RuntimeException('The restore health entry point is unavailable.');
        }

        if (! str_starts_with($workingDirectory, DIRECTORY_SEPARATOR)
            || ! is_dir($workingDirectory)
            || is_link($workingDirectory)
            || $this->timeoutSeconds < 1
        ) {
            throw new RuntimeException('The restore health runtime configuration is invalid.');
        }
    }
}
