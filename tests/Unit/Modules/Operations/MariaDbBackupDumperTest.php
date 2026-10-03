<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\MariaDbBackupDumper;
use RuntimeException;
use Tests\TestCase;

final class MariaDbBackupDumperTest extends TestCase
{
    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_credentials_are_confined_to_private_option_file_and_not_process_arguments(): void
    {
        $directory = $this->directory('success');
        $binary = $this->fakeBinary($directory);
        $destination = $directory.'/database.sql';
        $this->environment('MYSQL_PWD', 'test-only-inherited-mysql-secret');

        try {
            (new MariaDbBackupDumper(
                $binary,
                '127.0.0.1',
                3306,
                'freedom_test',
                'backup_user',
                'test-only-db-secret',
                30,
            ))->capture($destination);

            $arguments = json_decode(
                (string) file_get_contents($directory.'/capture-args.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($arguments);
            $argumentText = implode("\n", $arguments);
            self::assertStringNotContainsString('test-only-db-secret', $argumentText);
            self::assertStringNotContainsString('test-only-inherited-mysql-secret', $argumentText);
            self::assertStringStartsWith('--defaults-file=', (string) ($arguments[0] ?? ''));
            self::assertStringNotContainsString('--defaults-extra-file=', $argumentText);
            self::assertStringContainsString('--single-transaction', $argumentText);
            self::assertStringContainsString('--triggers', $argumentText);
            self::assertStringNotContainsString('--routines', $argumentText);
            self::assertStringNotContainsString('--events', $argumentText);
            self::assertStringContainsString('--result-file='.$destination, $argumentText);

            $options = (string) file_get_contents($directory.'/capture-options.txt');
            self::assertStringContainsString('password="test-only-db-secret"', $options);
            self::assertSame('unset', file_get_contents($directory.'/capture-mysql-pwd.txt'));
            self::assertSame('database-dump', file_get_contents($destination));
            self::assertSame(0600, fileperms($destination) & 0777);

            $optionArgument = collect($arguments)
                ->first(static fn (mixed $value): bool => is_string($value)
                    && str_starts_with($value, '--defaults-file='));
            self::assertIsString($optionArgument);
            self::assertFileDoesNotExist(substr($optionArgument, strlen('--defaults-file=')));
        } finally {
            $this->clearEnvironment('MYSQL_PWD');
            $this->removeTree($directory);
        }
    }

    /** @requirement BAK-001 SEC-001 QUA-001 */
    public function test_process_failure_does_not_echo_provider_output_or_leave_credentials(): void
    {
        $directory = $this->directory('failure');
        $binary = $this->fakeBinary($directory);
        $destination = $directory.'/database.sql';
        file_put_contents($directory.'/fail.flag', '1');

        try {
            try {
                (new MariaDbBackupDumper(
                    $binary,
                    '127.0.0.1',
                    3306,
                    'freedom_test',
                    'backup_user',
                    'test-only-db-secret',
                    30,
                ))->capture($destination);
                self::fail('A failed dump process must fail the backup.');
            } catch (RuntimeException $exception) {
                self::assertSame('The database backup process failed.', $exception->getMessage());
                self::assertStringNotContainsString('test-only-db-secret', $exception->getMessage());
            }

            self::assertFileDoesNotExist($destination);
            $arguments = json_decode(
                (string) file_get_contents($directory.'/capture-args.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $optionArgument = collect($arguments)
                ->first(static fn (mixed $value): bool => is_string($value)
                    && str_starts_with($value, '--defaults-file='));
            self::assertIsString($optionArgument);
            self::assertFileDoesNotExist(substr($optionArgument, strlen('--defaults-file=')));
        } finally {
            $this->removeTree($directory);
        }
    }

    private function fakeBinary(string $directory): string
    {
        $path = $directory.'/fake-mariadb-dump';
        $script = '#!'.PHP_BINARY."\n".<<<'PHP'
<?php
$args = array_slice($argv, 1);
$directory = getcwd();
file_put_contents($directory.'/capture-args.json', json_encode($args, JSON_THROW_ON_ERROR));

$optionFile = null;
$resultFile = null;
foreach ($args as $argument) {
    if (str_starts_with($argument, '--defaults-file=')) {
        $optionFile = substr($argument, strlen('--defaults-file='));
    }
    if (str_starts_with($argument, '--result-file=')) {
        $resultFile = substr($argument, strlen('--result-file='));
    }
}

if (! is_string($optionFile) || ! is_string($resultFile)) {
    exit(3);
}

file_put_contents($directory.'/capture-options.txt', (string) file_get_contents($optionFile));
file_put_contents(
    $directory.'/capture-mysql-pwd.txt',
    getenv('MYSQL_PWD') === false ? 'unset' : (string) getenv('MYSQL_PWD'),
);

if (is_file($directory.'/fail.flag')) {
    fwrite(STDERR, "test-only-db-secret\n");
    exit(2);
}

file_put_contents($resultFile, 'database-dump');
PHP;

        file_put_contents($path, $script);
        chmod($path, 0700);

        return $path;
    }

    private function environment(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function clearEnvironment(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/backup-dumper-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            @unlink($path.'/'.$name);
        }

        @rmdir($path);
    }
}
