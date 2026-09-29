<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\MariaDbRestoreExecutor;
use RuntimeException;
use Tests\TestCase;

final class MariaDbRestoreExecutorTest extends TestCase
{
    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_restore_credentials_are_confined_to_private_option_file_and_not_process_arguments(): void
    {
        $directory = $this->directory('success');
        $binary = $this->fakeBinary($directory);
        $sql = $directory.'/database.sql';
        file_put_contents($sql, "CREATE TABLE restore_probe (id INT);\n");
        chmod($sql, 0600);
        $this->environment('MYSQL_PWD', 'test-only-inherited-secret');

        try {
            (new MariaDbRestoreExecutor(
                $binary,
                '127.0.0.1',
                3306,
                'freedom_test',
                'restore_user',
                'test-only-restore-secret',
                30,
            ))->restore($sql);

            $arguments = json_decode(
                (string) file_get_contents($directory.'/restore-args.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($arguments);
            $argumentText = implode("\n", $arguments);
            self::assertStringStartsWith('--defaults-file=', (string) ($arguments[0] ?? ''));
            self::assertStringNotContainsString('test-only-restore-secret', $argumentText);
            self::assertStringNotContainsString('test-only-inherited-secret', $argumentText);
            self::assertStringContainsString('--database=freedom_test', $argumentText);
            self::assertSame('unset', file_get_contents($directory.'/restore-mysql-pwd.txt'));
            self::assertStringContainsString(
                'password="test-only-restore-secret"',
                (string) file_get_contents($directory.'/restore-options.txt'),
            );
            self::assertSame(
                file_get_contents($sql),
                file_get_contents($directory.'/restore-input.sql'),
            );

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

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_process_failure_is_redacted_and_removes_temporary_credentials(): void
    {
        $directory = $this->directory('failure');
        $binary = $this->fakeBinary($directory);
        $sql = $directory.'/database.sql';
        file_put_contents($sql, "SELECT 'test-only-restore-secret';\n");
        chmod($sql, 0600);
        file_put_contents($directory.'/fail.flag', '1');

        try {
            try {
                (new MariaDbRestoreExecutor(
                    $binary,
                    '127.0.0.1',
                    3306,
                    'freedom_test',
                    'restore_user',
                    'test-only-restore-secret',
                    30,
                ))->restore($sql);
                self::fail('Failed MariaDB restore process must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame('The database restore process failed.', $exception->getMessage());
                self::assertStringNotContainsString('test-only-restore-secret', $exception->getMessage());
            }

            $arguments = json_decode(
                (string) file_get_contents($directory.'/restore-args.json'),
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
        $path = $directory.'/fake-mariadb';
        $script = '#!'.PHP_BINARY."\n".<<<'PHP'
<?php
$args = array_slice($argv, 1);
$directory = getcwd();
file_put_contents($directory.'/restore-args.json', json_encode($args, JSON_THROW_ON_ERROR));

$optionFile = null;
foreach ($args as $argument) {
    if (str_starts_with($argument, '--defaults-file=')) {
        $optionFile = substr($argument, strlen('--defaults-file='));
    }
}

if (! is_string($optionFile)) {
    exit(3);
}

file_put_contents($directory.'/restore-options.txt', (string) file_get_contents($optionFile));
file_put_contents(
    $directory.'/restore-mysql-pwd.txt',
    getenv('MYSQL_PWD') === false ? 'unset' : (string) getenv('MYSQL_PWD'),
);
file_put_contents($directory.'/restore-input.sql', (string) stream_get_contents(STDIN));

if (is_file($directory.'/fail.flag')) {
    fwrite(STDERR, "test-only-restore-secret\n");
    exit(2);
}
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
        $path = storage_path('framework/testing/restore-executor-'.$case.'-'.bin2hex(random_bytes(4)));
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

            $entry = $path.'/'.$name;
            if (is_dir($entry) && ! is_link($entry)) {
                $this->removeTree($entry);
            } else {
                @unlink($entry);
            }
        }

        @rmdir($path);
    }
}
