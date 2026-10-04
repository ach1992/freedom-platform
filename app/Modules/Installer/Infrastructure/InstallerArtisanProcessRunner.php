<?php

declare(strict_types=1);

namespace App\Modules\Installer\Infrastructure;

use App\Modules\Installer\Application\Contracts\InstallerFinalizationRunner;
use App\Modules\Installer\Application\InstallerProductionEnvironmentPolicy;
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
        $this->run(
            'config_clear',
            ['config:clear', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );
    }

    public function migrate(?string $lifecycleDatabasePassword = null): void
    {
        $this->run(
            'migrations',
            ['migrate', '--force', '--isolated=1', '--no-ansi', '--no-interaction'],
            [
                'TELEGRAM_LIFECYCLE_DB_PASSWORD' => $lifecycleDatabasePassword === null
                    ? false
                    : $lifecycleDatabasePassword,
            ],
        );
    }

    public function seed(): void
    {
        $this->run(
            'seed',
            ['db:seed', '--force', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );
    }

    public function bootstrapOwner(): void
    {
        $this->run(
            'owner_bootstrap',
            ['installer:bootstrap-owner', '--json', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );
    }

    public function cacheConfiguration(): void
    {
        $this->run(
            'config_cache',
            ['config:cache', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );
    }

    public function configureTelegramWebhook(): void
    {
        $this->run(
            'telegram_webhook',
            ['telegram:webhook:configure', '--json', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );
    }

    public function verifyTelegramReportChannel(): void
    {
        $this->run(
            'telegram_report_channel',
            ['telegram:report-channel:verify', '--json', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );
    }

    public function verifyHealth(): void
    {
        $output = $this->run(
            'health',
            ['health:check', '--critical', '--json', '--redact', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );

        $decoded = json_decode($output, true);
        if (! is_array($decoded) || ($decoded['status'] ?? null) !== 'healthy') {
            throw new RuntimeException('The installer finalization health result is invalid.');
        }
    }

    public function verifyScheduler(): void
    {
        $output = $this->run(
            'scheduler',
            ['schedule:list', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );

        if (! str_contains($output, 'operations.scheduler-heartbeat')) {
            throw new RuntimeException('The installer finalization Scheduler readiness surface is incomplete.');
        }
    }

    public function writeInstallationReport(): void
    {
        $this->run(
            'installation_report',
            ['installer:write-report', '--json', '--no-ansi', '--no-interaction'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => false],
        );
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    private function run(string $step, array $arguments, array $environment): string
    {
        $this->validateRuntime();

        $environment = array_replace(
            array_fill_keys(InstallerProductionEnvironmentPolicy::sensitiveKeys(), false),
            $environment,
        );

        $process = new Process(
            [$this->phpBinary, $this->artisanPath, ...$arguments],
            $this->workingDirectory,
            $environment,
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

        return $process->getOutput();
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
