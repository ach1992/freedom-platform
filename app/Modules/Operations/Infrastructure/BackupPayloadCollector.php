<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\BackupPayloadCollector as BackupPayloadCollectorContract;
use RuntimeException;

final readonly class BackupPayloadCollector implements BackupPayloadCollectorContract
{
    /**
     * @param  array<string, string>  $configFiles
     * @param  array<string, string>  $privateDirectories
     */
    public function __construct(
        private array $configFiles,
        private array $privateDirectories,
    ) {}

    /**
     * @return array<string, string>
     */
    public function fullPayload(): array
    {
        $entries = [];

        foreach ($this->configFiles as $name => $path) {
            $this->assertRegularFile($path);
            $entries['config/'.$name] = (string) realpath($path);
        }

        foreach ($this->privateDirectories as $name => $directory) {
            $root = $this->safeDirectory($directory);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root,
                    \FilesystemIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Backup private input contains a symbolic link.');
                }
                if (! $file->isFile() || ! $file->isReadable()) {
                    throw new RuntimeException('Backup private input contains an unreadable entry.');
                }

                $real = $file->getRealPath();
                if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('Backup private input escapes its configured root.');
                }

                $relative = substr($real, strlen($root) + 1);
                $entries['private/'.$name.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $real;
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    private function assertRegularFile(string $path): void
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path) || realpath($path) === false) {
            throw new RuntimeException('A configured backup file is unavailable or unsafe.');
        }
    }

    private function safeDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real) || is_link($path) || ! is_readable($real)) {
            throw new RuntimeException('A configured backup directory is unavailable or unsafe.');
        }

        return $real;
    }
}
