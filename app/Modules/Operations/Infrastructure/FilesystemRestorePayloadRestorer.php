<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestorePayloadRestorer;
use RuntimeException;
use Throwable;

final readonly class FilesystemRestorePayloadRestorer implements RestorePayloadRestorer
{
    /**
     * @param  array<string, string>  $configFiles
     * @param  array<string, string>  $privateDirectories
     */
    public function __construct(
        private array $configFiles,
        private array $privateDirectories,
    ) {}

    /** @param array<string, string> $entries */
    public function restore(array $entries, string $restoreRunId): void
    {
        $this->assertRunId($restoreRunId);
        $this->preflight($entries);
        $operations = [];

        foreach ($this->configFiles as $name => $configuredTarget) {
            $source = $entries['config/'.$name];
            $target = $this->safeFileTarget($configuredTarget);
            $suffix = hash('sha256', $target);
            $stage = dirname($target).'/.restore-'.$restoreRunId.'-'.$suffix.'.next';
            $previous = dirname($target).'/.restore-'.$restoreRunId.'-'.$suffix.'.previous';

            $this->assertSwapPathsAvailable($stage, $previous);
            $this->copyRegularFile($source, $stage);
            $operations[] = [
                'target' => $target,
                'stage' => $stage,
                'previous' => $previous,
                'had_original' => file_exists($target),
            ];
        }

        foreach ($this->privateDirectories as $name => $configuredTarget) {
            $target = $this->safeDirectoryTarget($configuredTarget);
            $suffix = hash('sha256', $target);
            $stage = dirname($target).'/.restore-'.$restoreRunId.'-'.$suffix.'.next';
            $previous = dirname($target).'/.restore-'.$restoreRunId.'-'.$suffix.'.previous';

            $this->assertSwapPathsAvailable($stage, $previous);
            if (! mkdir($stage, 0700) || ! chmod($stage, 0700)) {
                throw new RuntimeException('A private restore staging directory could not be secured.');
            }

            $prefix = 'private/'.$name.'/';
            foreach ($entries as $logicalPath => $source) {
                if (! str_starts_with($logicalPath, $prefix)) {
                    continue;
                }

                $relative = substr($logicalPath, strlen($prefix));
                if ($relative === '') {
                    throw new RuntimeException('A private restore entry is invalid.');
                }

                $destination = $stage.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->createDirectoriesUnder($stage, dirname($destination));
                $this->copyRegularFile($source, $destination);
            }

            $operations[] = [
                'target' => $target,
                'stage' => $stage,
                'previous' => $previous,
                'had_original' => file_exists($target),
            ];
        }

        $committed = [];

        try {
            foreach ($operations as $operation) {
                if ($operation['had_original'] && ! rename($operation['target'], $operation['previous'])) {
                    throw new RuntimeException('A restore target could not be staged for replacement.');
                }

                if (! rename($operation['stage'], $operation['target'])) {
                    if ($operation['had_original'] && file_exists($operation['previous'])) {
                        @rename($operation['previous'], $operation['target']);
                    }

                    throw new RuntimeException('A staged restore target could not be activated.');
                }

                $committed[] = $operation;
            }
        } catch (Throwable $throwable) {
            for ($index = count($committed) - 1; $index >= 0; $index--) {
                $operation = $committed[$index];

                if (file_exists($operation['target']) || is_link($operation['target'])) {
                    $this->removeTree($operation['target']);
                }

                if ($operation['had_original']
                    && file_exists($operation['previous'])
                    && ! rename($operation['previous'], $operation['target'])
                ) {
                    throw new RuntimeException('Restore payload rollback could not restore the previous target.', 0, $throwable);
                }
            }

            throw $throwable;
        } finally {
            foreach ($operations as $operation) {
                if (file_exists($operation['stage']) || is_link($operation['stage'])) {
                    $this->removeTree($operation['stage']);
                }
            }
        }

        foreach ($operations as $operation) {
            if (file_exists($operation['previous']) || is_link($operation['previous'])) {
                $this->removeTree($operation['previous']);
            }
        }
    }

    /** @param array<string, string> $entries */
    public function preflight(array $entries): void
    {
        if (! isset($entries['database/database.sql'])) {
            throw new RuntimeException('The restore bundle database payload is missing.');
        }

        foreach ($this->configFiles as $name => $_path) {
            if (! isset($entries['config/'.$name])) {
                throw new RuntimeException('A required restore configuration payload is missing.');
            }
        }

        foreach ($entries as $logicalPath => $path) {
            if (! is_file($path) || is_link($path) || ! is_readable($path)) {
                throw new RuntimeException('A restore payload source is unavailable or unsafe.');
            }

            if ($logicalPath === 'database/database.sql') {
                continue;
            }

            $segments = explode('/', $logicalPath);
            if ($segments[0] === 'config') {
                if (count($segments) !== 2 || ! array_key_exists($segments[1], $this->configFiles)) {
                    throw new RuntimeException('The restore bundle contains an unknown configuration target.');
                }

                continue;
            }

            if ($segments[0] === 'private') {
                if (count($segments) < 3 || ! array_key_exists($segments[1], $this->privateDirectories)) {
                    throw new RuntimeException('The restore bundle contains an unknown private target.');
                }

                continue;
            }

            throw new RuntimeException('The restore bundle contains an unsupported payload target.');
        }
    }

    private function safeFileTarget(string $configuredPath): string
    {
        if (! str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            || str_contains($configuredPath, "\0")
            || is_link($configuredPath)
        ) {
            throw new RuntimeException('A restore configuration target is unsafe.');
        }

        $parent = realpath(dirname($configuredPath));
        if ($parent === false || ! is_dir($parent)) {
            throw new RuntimeException('A restore configuration target parent is unavailable.');
        }

        $target = $parent.'/'.basename($configuredPath);
        if (file_exists($target) && ! is_file($target)) {
            throw new RuntimeException('A restore configuration target is not a regular file.');
        }

        return $target;
    }

    private function safeDirectoryTarget(string $configuredPath): string
    {
        if (! str_starts_with($configuredPath, DIRECTORY_SEPARATOR)
            || str_contains($configuredPath, "\0")
            || is_link($configuredPath)
        ) {
            throw new RuntimeException('A private restore target is unsafe.');
        }

        $parent = realpath(dirname($configuredPath));
        if ($parent === false || ! is_dir($parent)) {
            throw new RuntimeException('A private restore target parent is unavailable.');
        }

        $target = $parent.'/'.basename($configuredPath);
        if (file_exists($target) && ! is_dir($target)) {
            throw new RuntimeException('A private restore target is not a directory.');
        }

        return $target;
    }

    private function assertSwapPathsAvailable(string $stage, string $previous): void
    {
        foreach ([$stage, $previous] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('A restore swap path already exists.');
            }
        }
    }

    private function copyRegularFile(string $source, string $destination): void
    {
        if (! is_file($source) || is_link($source) || ! is_readable($source)
            || file_exists($destination) || is_link($destination)
        ) {
            throw new RuntimeException('A restore file copy boundary is unsafe.');
        }

        if (! copy($source, $destination) || ! chmod($destination, 0600)) {
            throw new RuntimeException('A restore file could not be staged securely.');
        }
    }

    private function createDirectoriesUnder(string $root, string $directory): void
    {
        if (is_dir($directory)) {
            $real = realpath($directory);
            $rootReal = realpath($root);
            if ($real === false || $rootReal === false
                || ($real !== $rootReal && ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR))
            ) {
                throw new RuntimeException('A private restore staging path is unsafe.');
            }

            return;
        }

        if (file_exists($directory) || is_link($directory)
            || ! mkdir($directory, 0700, true)
            || ! chmod($directory, 0700)
        ) {
            throw new RuntimeException('A private restore staging path could not be secured.');
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('A restore swap path contains an unsafe symbolic link.');
        }

        if (is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('A restore swap file could not be removed.');
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
            throw new RuntimeException('A restore swap directory could not be removed.');
        }
    }

    private function assertRunId(string $restoreRunId): void
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $restoreRunId) !== 1) {
            throw new RuntimeException('The restore run identifier is invalid.');
        }
    }
}
