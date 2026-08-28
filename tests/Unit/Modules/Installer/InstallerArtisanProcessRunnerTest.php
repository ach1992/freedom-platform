<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Infrastructure\InstallerArtisanProcessRunner;
use Closure;
use RuntimeException;
use Tests\TestCase;

final class InstallerArtisanProcessRunnerTest extends TestCase
{
    /** @requirement INS-001 SEC-007 SEC-008 QUA-011 */
    public function test_it_executes_only_the_fixed_finalization_commands(): void
    {
        [$directory, $artisanPath, $logPath, $environmentLogPath, $failurePath] = $this->fixture('commands');
        $this->writeArtisanFixture($artisanPath, $logPath, $environmentLogPath, $failurePath);
        $runner = new InstallerArtisanProcessRunner(PHP_BINARY, $artisanPath, $directory, 5);

        try {
            $runner->clearConfiguration();
            $runner->migrate();
            $runner->cacheConfiguration();

            $this->assertSame([
                'config:clear --no-ansi --no-interaction',
                'migrate --force --isolated=1 --no-ansi --no-interaction',
                'config:cache --no-ansi --no-interaction',
            ], file($logPath, FILE_IGNORE_NEW_LINES));
            $this->assertSame([
                'config:clear=<absent>',
                'migrate=<absent>',
                'config:cache=<absent>',
            ], file($environmentLogPath, FILE_IGNORE_NEW_LINES));
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-007 SEC-008 QUA-011 */
    public function test_lifecycle_password_is_injected_only_into_the_migration_child(): void
    {
        [$directory, $artisanPath, $logPath, $environmentLogPath, $failurePath] = $this->fixture('lifecycle-env');
        $this->writeArtisanFixture($artisanPath, $logPath, $environmentLogPath, $failurePath);
        $runner = new InstallerArtisanProcessRunner(PHP_BINARY, $artisanPath, $directory, 5);
        $parentMarker = 'test-only-parent-lifecycle-secret';
        $migrationMarker = 'test-only-migration-lifecycle-secret';

        try {
            $this->withEnvironment(['TELEGRAM_LIFECYCLE_DB_PASSWORD' => $parentMarker], function () use ($runner, $migrationMarker): void {
                $runner->clearConfiguration();
                $runner->migrate($migrationMarker);
                $runner->cacheConfiguration();
            });

            $this->assertSame([
                'config:clear=<absent>',
                'migrate='.$migrationMarker,
                'config:cache=<absent>',
            ], file($environmentLogPath, FILE_IGNORE_NEW_LINES));
            $this->assertStringNotContainsString($parentMarker, (string) file_get_contents($environmentLogPath));
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-007 SEC-008 QUA-011 */
    public function test_config_cache_cannot_capture_a_parent_lifecycle_password(): void
    {
        $cachePath = storage_path('framework/testing/installer-config-cache-'.bin2hex(random_bytes(4)).'.php');
        $marker = 'test-only-lifecycle-cache-marker-'.bin2hex(random_bytes(8));
        $runner = new InstallerArtisanProcessRunner(PHP_BINARY, base_path('artisan'), base_path(), 60);

        try {
            $this->withEnvironment([
                'APP_CONFIG_CACHE' => $cachePath,
                'TELEGRAM_LIFECYCLE_DB_PASSWORD' => $marker,
            ], function () use ($runner): void {
                $runner->cacheConfiguration();
            });

            $this->assertFileExists($cachePath);
            $contents = (string) file_get_contents($cachePath);
            $this->assertStringNotContainsString($marker, $contents);

            $cached = require $cachePath;
            $this->assertIsArray($cached);
            $this->assertNull($cached['database']['connections']['telegram_lifecycle']['password'] ?? null);
        } finally {
            @unlink($cachePath);
        }
    }

    /** @requirement INS-001 SEC-007 SEC-008 QUA-011 */
    public function test_failed_process_output_is_not_exposed(): void
    {
        [$directory, $artisanPath, $logPath, $environmentLogPath, $failurePath] = $this->fixture('failure');
        $this->writeArtisanFixture($artisanPath, $logPath, $environmentLogPath, $failurePath);
        file_put_contents($failurePath, 'fail');
        $runner = new InstallerArtisanProcessRunner(PHP_BINARY, $artisanPath, $directory, 5);

        try {
            try {
                $runner->migrate('test-only-sensitive-password');
                $this->fail('A failed migration process must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer finalization process failed the migrations step.',
                    $exception->getMessage(),
                );
                $this->assertStringNotContainsString('test-only-sensitive-output', $exception->getMessage());
                $this->assertStringNotContainsString('test-only-sensitive-password', $exception->getMessage());
            }
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @return array{string, string, string, string, string} */
    private function fixture(string $case): array
    {
        $directory = storage_path('framework/testing/installer-process-'.$case.'-'.bin2hex(random_bytes(4)));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Could not create process runner fixture directory.');
        }

        return [
            $directory,
            $directory.'/artisan.php',
            $directory.'/commands.log',
            $directory.'/environment.log',
            $directory.'/fail-migration',
        ];
    }

    private function writeArtisanFixture(
        string $artisanPath,
        string $logPath,
        string $environmentLogPath,
        string $failurePath,
    ): void {
        $script = sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

$command = implode(' ', array_slice($argv, 1));
file_put_contents(%s, $command.PHP_EOL, FILE_APPEND);
$lifecycle = getenv('TELEGRAM_LIFECYCLE_DB_PASSWORD');
file_put_contents(
    %s,
    ($argv[1] ?? '<unknown>').'='.($lifecycle === false ? '<absent>' : $lifecycle).PHP_EOL,
    FILE_APPEND,
);

if (($argv[1] ?? null) === 'migrate' && is_file(%s)) {
    fwrite(STDERR, 'test-only-sensitive-output');
    exit(17);
}
PHP,
            var_export($logPath, true),
            var_export($environmentLogPath, true),
            var_export($failurePath, true),
        );

        file_put_contents($artisanPath, $script);
    }

    /** @param array<string,string> $values */
    private function withEnvironment(array $values, Closure $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = [
                'process' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $state) {
                if ($state['process'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['process']);
                }

                if ($state['env_exists']) {
                    $_ENV[$key] = $state['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$key] = $state['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    private function cleanup(string $directory): void
    {
        foreach (['fail-migration', 'environment.log', 'commands.log', 'artisan.php'] as $file) {
            @unlink($directory.'/'.$file);
        }

        @rmdir($directory);
    }
}
