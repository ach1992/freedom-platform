<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\UpdateReleaseExecutor;
use App\Modules\Operations\Application\UpdateRuntimeConfiguration;
use App\Modules\Operations\Application\VerifiedUpdatePackage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class ArtisanUpdateReleaseExecutor implements UpdateReleaseExecutor
{
    public function __construct(private UpdateRuntimeConfiguration $configuration) {}

    /** @requirement UPD-001 RUN-001 RUN-006 SEC-008 QUA-001 */
    public function assertPrerequisites(VerifiedUpdatePackage $package): void
    {
        foreach ([$this->configuration->phpBinary, $this->configuration->composerBinary, '/usr/bin/id'] as $binary) {
            if (! is_file($binary) || is_link($binary) || ! is_executable($binary)) {
                throw new RuntimeException('An allowlisted update runtime binary is unavailable.');
            }
        }

        $root = realpath($this->configuration->deploymentRoot);
        if ($root === false || ! is_dir($root) || is_link($this->configuration->deploymentRoot)) {
            throw new RuntimeException('The update deployment root is unavailable.');
        }

        $releases = realpath($root.'/releases');
        if ($releases === false || ! is_dir($releases) || dirname($releases) !== $root || ! is_writable($releases)) {
            throw new RuntimeException('The update release staging root is unavailable or not writable.');
        }

        $identity = $this->process(['/usr/bin/id', '-un'], $root, 30);
        if (! hash_equals($this->configuration->runUser, trim($identity))) {
            throw new RuntimeException('The updater must run as the reviewed non-root runtime user.');
        }

        $free = disk_free_space($root);
        $required = max($package->requiredFreeBytes, $package->payloadBytes * 3);
        if ($free === false) {
            throw new RuntimeException('Update disk capacity could not be determined.');
        }
        if ($free < $required) {
            throw new RuntimeException('Update disk capacity is insufficient.');
        }

        if (version_compare(PHP_VERSION, $package->phpMinimum, '<')
            || version_compare(PHP_VERSION, $package->phpMaximumExclusive, '>=')
        ) {
            throw new RuntimeException('The active updater PHP runtime is incompatible with the release.');
        }
    }

    /** @requirement UPD-001 RUN-006 SEC-008 QUA-001 */
    public function prepare(string $releasePath, VerifiedUpdatePackage $package): void
    {
        $release = $this->releasePath($releasePath);

        $lockHash = hash_file('sha256', $release.'/composer.lock');
        if (! is_string($lockHash) || ! hash_equals($package->composerLockSha256, $lockHash)) {
            throw new RuntimeException('The staged Composer lock identity changed before dependency installation.');
        }

        $this->process([
            $this->configuration->composerBinary,
            'validate',
            '--strict',
            '--no-check-publish',
            '--no-interaction',
        ], $release);

        $this->process([
            $this->configuration->composerBinary,
            'install',
            '--no-dev',
            '--prefer-dist',
            '--optimize-autoloader',
            '--no-interaction',
            '--no-progress',
        ], $release);

        $this->process([
            $this->configuration->composerBinary,
            'check-platform-reqs',
            '--no-dev',
            '--no-interaction',
        ], $release);

        $afterHash = hash_file('sha256', $release.'/composer.lock');
        if (! is_string($afterHash) || ! hash_equals($package->composerLockSha256, $afterHash)) {
            throw new RuntimeException('Dependency preparation changed the reviewed Composer lock.');
        }
    }

    /** @requirement UPD-001 DAT-004 QUA-001 */
    public function migrate(string $releasePath): void
    {
        $release = $this->releasePath($releasePath);

        $this->process([
            $this->configuration->phpBinary,
            $release.'/artisan',
            'migrate',
            '--force',
            '--isolated=1',
            '--no-ansi',
            '--no-interaction',
        ], $release);
    }

    /** @requirement UPD-001 RUN-001 RUN-003 RUN-006 OPS-001 QUA-001 */
    public function verifyRelease(string $releasePath, ?VerifiedUpdatePackage $package = null): void
    {
        $release = $this->releasePath($releasePath);

        $workerWrapper = $release.'/deploy/bin/queue-worker-with-heartbeat.sh';
        if (! is_file($workerWrapper) || is_link($workerWrapper) || ! is_executable($workerWrapper)) {
            throw new RuntimeException('The exact-release worker entrypoint is unavailable or not executable.');
        }

        $healthOutput = $this->process([
            $this->configuration->phpBinary,
            $release.'/artisan',
            'health:check',
            '--critical',
            '--json',
            '--redact',
            '--no-ansi',
            '--no-interaction',
        ], $release);
        $health = $this->json($healthOutput, 'The exact-release health output is invalid.');
        if (($health['status'] ?? null) !== 'healthy') {
            throw new RuntimeException('The exact-release critical health contract failed.');
        }

        $routesOutput = $this->process([
            $this->configuration->phpBinary,
            $release.'/artisan',
            'route:list',
            '--json',
            '--no-ansi',
            '--no-interaction',
        ], $release);
        try {
            $routes = json_decode($routesOutput, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException('The exact-release route readiness output is invalid.', 0, $throwable);
        }
        if (! is_array($routes)) {
            throw new RuntimeException('The exact-release route readiness output is invalid.');
        }

        $names = [];
        foreach ($routes as $route) {
            if (is_array($route) && is_string($route['name'] ?? null)) {
                $names[] = $route['name'];
            }
        }
        foreach (['telegram.webhook', 'payments.nowpayments.ipn'] as $requiredRoute) {
            if (! in_array($requiredRoute, $names, true)) {
                throw new RuntimeException('The exact-release provider/webhook readiness surface is incomplete.');
            }
        }

        $scheduleOutput = $this->process([
            $this->configuration->phpBinary,
            $release.'/artisan',
            'schedule:list',
            '--no-ansi',
            '--no-interaction',
        ], $release);
        if (! str_contains($scheduleOutput, 'operations.scheduler-heartbeat')) {
            throw new RuntimeException('The exact-release Scheduler readiness surface is incomplete.');
        }

        $this->process([
            $this->configuration->composerBinary,
            'check-platform-reqs',
            '--no-dev',
            '--no-interaction',
        ], $release);

        if ($package !== null) {
            $lockHash = hash_file('sha256', $release.'/composer.lock');
            if (! is_string($lockHash) || ! hash_equals($package->composerLockSha256, $lockHash)) {
                throw new RuntimeException('The exact-release dependency identity changed during validation.');
            }
        }
    }

    private function releasePath(string $releasePath): string
    {
        if (! str_starts_with($releasePath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The update release path must be absolute.');
        }

        $release = realpath($releasePath);
        if ($release === false || ! is_dir($release) || is_link($releasePath)) {
            throw new RuntimeException('The update release path is unavailable or unsafe.');
        }

        foreach (['artisan', 'composer.json', 'composer.lock'] as $required) {
            if (! is_file($release.'/'.$required) || is_link($release.'/'.$required)) {
                throw new RuntimeException('The update release is incomplete.');
            }
        }

        return $release;
    }

    /** @param list<string> $command */
    private function process(array $command, string $workingDirectory, ?int $timeout = null): string
    {
        $process = new Process(
            $command,
            $workingDirectory,
            null,
            null,
            $timeout ?? $this->configuration->processTimeoutSeconds,
        );

        try {
            $process->run();
        } catch (Throwable $throwable) {
            throw new RuntimeException('An allowlisted update subprocess could not be executed.', 0, $throwable);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('An allowlisted update subprocess failed.');
        }

        return $process->getOutput();
    }

    /** @return array<string, mixed> */
    private function json(string $contents, string $message): array
    {
        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException($message, 0, $throwable);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($message);
        }

        return $decoded;
    }
}
