<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\ArtisanRestoreRuntimeHealthVerifier;
use RuntimeException;
use Tests\TestCase;

final class ArtisanRestoreRuntimeHealthVerifierTest extends TestCase
{
    /** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
    public function test_health_check_runs_in_fresh_process_with_restored_runtime_flags_and_without_lifecycle_secret(): void
    {
        $directory = $this->directory('success');
        $binary = $this->fakeBinary($directory);
        $this->environment('TELEGRAM_LIFECYCLE_DB_PASSWORD', 'test-only-lifecycle-secret');

        try {
            (new ArtisanRestoreRuntimeHealthVerifier(
                $binary,
                base_path('artisan'),
                $directory,
                30,
            ))->verify();

            $arguments = json_decode(
                (string) file_get_contents($directory.'/health-args.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            self::assertSame([
                base_path('artisan'),
                'health:check',
                '--critical',
                '--json',
                '--redact',
                '--no-ansi',
                '--no-interaction',
            ], $arguments);
            self::assertSame('unset', file_get_contents($directory.'/health-lifecycle-secret.txt'));
        } finally {
            $this->clearEnvironment('TELEGRAM_LIFECYCLE_DB_PASSWORD');
            $this->removeTree($directory);
        }
    }

    /** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
    public function test_health_failure_is_redacted_and_fails_closed(): void
    {
        $directory = $this->directory('failure');
        $binary = $this->fakeBinary($directory);
        file_put_contents($directory.'/fail.flag', '1');

        try {
            try {
                (new ArtisanRestoreRuntimeHealthVerifier(
                    $binary,
                    base_path('artisan'),
                    $directory,
                    30,
                ))->verify();
                self::fail('A failed restored-runtime health check must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame('The restored runtime health checks failed.', $exception->getMessage());
                self::assertStringNotContainsString('test-only-health-secret', $exception->getMessage());
            }
        } finally {
            $this->removeTree($directory);
        }
    }

    private function fakeBinary(string $directory): string
    {
        $path = $directory.'/fake-php';
        $script = '#!'.PHP_BINARY."\n".<<<'PHP'
<?php
$directory = getcwd();
file_put_contents(
    $directory.'/health-args.json',
    json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR),
);
file_put_contents(
    $directory.'/health-lifecycle-secret.txt',
    getenv('TELEGRAM_LIFECYCLE_DB_PASSWORD') === false
        ? 'unset'
        : (string) getenv('TELEGRAM_LIFECYCLE_DB_PASSWORD'),
);

if (is_file($directory.'/fail.flag')) {
    fwrite(STDERR, "test-only-health-secret\n");
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
        $path = storage_path('framework/testing/restore-runtime-health-'.$case.'-'.bin2hex(random_bytes(4)));
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
