<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\BackupDatabaseDumper;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class MariaDbBackupDumper implements BackupDatabaseDumper
{
    public function __construct(
        private string $binary,
        private string $host,
        private int $port,
        private string $database,
        private string $username,
        private string $password,
        private int $timeoutSeconds,
    ) {}

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function capture(string $destinationPath): void
    {
        $this->assertRuntime($destinationPath);
        $optionFile = dirname($destinationPath).'/.backup-db-client-'.bin2hex(random_bytes(8)).'.cnf';

        try {
            $this->writeOptionFile($optionFile);

            $process = new Process(
                [
                    $this->binary,
                    '--defaults-file='.$optionFile,
                    '--single-transaction',
                    '--quick',
                    '--skip-lock-tables',
                    '--hex-blob',
                    '--triggers',
                    '--default-character-set=utf8mb4',
                    '--result-file='.$destinationPath,
                    $this->database,
                ],
                dirname($destinationPath),
                ['MYSQL_PWD' => false],
                null,
                max(1, $this->timeoutSeconds),
            );
            $process->setIdleTimeout(max(1, $this->timeoutSeconds));

            try {
                $process->run();
            } catch (Throwable) {
                throw new RuntimeException('The database backup process could not complete.');
            }

            if (! $process->isSuccessful()
                || ! is_file($destinationPath)
                || is_link($destinationPath)
                || filesize($destinationPath) === false
            ) {
                throw new RuntimeException('The database backup process failed.');
            }

            if (! chmod($destinationPath, 0600)) {
                throw new RuntimeException('The database backup permissions could not be secured.');
            }
        } finally {
            if (is_file($optionFile)) {
                @unlink($optionFile);
            }
        }
    }

    private function assertRuntime(string $destinationPath): void
    {
        $binary = realpath($this->binary);
        if ($binary === false || ! is_file($binary) || ! is_executable($binary)) {
            throw new RuntimeException('The MariaDB backup binary is unavailable.');
        }

        if (! str_starts_with($destinationPath, DIRECTORY_SEPARATOR)
            || ! is_dir(dirname($destinationPath))
            || file_exists($destinationPath)
            || is_link($destinationPath)
        ) {
            throw new RuntimeException('The database backup destination is invalid.');
        }

        foreach ([
            'host' => $this->host,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ] as $name => $value) {
            if ($value === '' || str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException('The MariaDB backup '.$name.' configuration is invalid.');
            }
        }

        if ($this->port < 1 || $this->port > 65535 || $this->timeoutSeconds < 1) {
            throw new RuntimeException('The MariaDB backup connection configuration is invalid.');
        }
    }

    private function writeOptionFile(string $path): void
    {
        $contents = implode("\n", [
            '[client]',
            'protocol=tcp',
            'host='.$this->quoteOptionValue($this->host),
            'port='.$this->port,
            'user='.$this->quoteOptionValue($this->username),
            'password='.$this->quoteOptionValue($this->password),
            '',
        ]);

        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('The database backup credential file could not be created.');
        }

        try {
            if (! chmod($path, 0600)
                || fwrite($handle, $contents) !== strlen($contents)
                || ! fflush($handle)
            ) {
                throw new RuntimeException('The database backup credential file could not be secured.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function quoteOptionValue(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
