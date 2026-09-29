<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreDatabaseRestorer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class MariaDbRestoreExecutor implements RestoreDatabaseRestorer
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

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function restore(string $sqlPath): void
    {
        $this->assertRuntime($sqlPath);
        $optionFile = dirname($sqlPath).'/.restore-db-client-'.bin2hex(random_bytes(8)).'.cnf';
        $input = null;

        try {
            $this->writeOptionFile($optionFile);
            $input = fopen($sqlPath, 'rb');
            if ($input === false) {
                throw new RuntimeException('The database restore source could not be opened.');
            }

            $process = new Process(
                [
                    $this->binary,
                    '--defaults-file='.$optionFile,
                    '--database='.$this->database,
                    '--default-character-set=utf8mb4',
                    '--binary-mode',
                ],
                dirname($sqlPath),
                ['MYSQL_PWD' => false],
                null,
                max(1, $this->timeoutSeconds),
            );
            $process->setIdleTimeout(max(1, $this->timeoutSeconds));
            $process->setInput($input);

            try {
                $process->run();
            } catch (Throwable) {
                throw new RuntimeException('The database restore process could not complete.');
            }

            if (! $process->isSuccessful()) {
                throw new RuntimeException('The database restore process failed.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_file($optionFile)) {
                @unlink($optionFile);
            }
        }
    }

    private function assertRuntime(string $sqlPath): void
    {
        $binary = realpath($this->binary);
        if ($binary === false || ! is_file($binary) || ! is_executable($binary)) {
            throw new RuntimeException('The MariaDB restore binary is unavailable.');
        }

        if (! str_starts_with($sqlPath, DIRECTORY_SEPARATOR)
            || ! is_file($sqlPath)
            || is_link($sqlPath)
            || ! is_readable($sqlPath)
        ) {
            throw new RuntimeException('The database restore source is unavailable or unsafe.');
        }

        foreach ([
            'host' => $this->host,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ] as $name => $value) {
            if ($value === '' || str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException('The MariaDB restore '.$name.' configuration is invalid.');
            }
        }

        if ($this->port < 1 || $this->port > 65535 || $this->timeoutSeconds < 1) {
            throw new RuntimeException('The MariaDB restore connection configuration is invalid.');
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
            throw new RuntimeException('The database restore credential file could not be created.');
        }

        try {
            if (! chmod($path, 0600)
                || fwrite($handle, $contents) !== strlen($contents)
                || ! fflush($handle)
            ) {
                throw new RuntimeException('The database restore credential file could not be secured.');
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
