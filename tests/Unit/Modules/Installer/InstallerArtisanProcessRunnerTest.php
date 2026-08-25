<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Infrastructure\InstallerArtisanProcessRunner;
use RuntimeException;
use Tests\TestCase;

final class InstallerArtisanProcessRunnerTest extends TestCase
{
    /** @requirement INS-001 SEC-007 SEC-008 QUA-011 */
    public function test_it_executes_only_the_fixed_finalization_commands(): void
    {
        [$directory, $artisanPath, $logPath, $failurePath] = $this->fixture('commands');
        $this->writeArtisanFixture($artisanPath, $logPath, $failurePath);
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
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-007 SEC-008 QUA-011 */
    public function test_failed_process_output_is_not_exposed(): void
    {
        [$directory, $artisanPath, $logPath, $failurePath] = $this->fixture('failure');
        $this->writeArtisanFixture($artisanPath, $logPath, $failurePath);
        file_put_contents($failurePath, 'fail');
        $runner = new InstallerArtisanProcessRunner(PHP_BINARY, $artisanPath, $directory, 5);

        try {
            try {
                $runner->migrate();
                $this->fail('A failed migration process must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer finalization process failed the migrations step.',
                    $exception->getMessage(),
                );
                $this->assertStringNotContainsString('test-only-sensitive-output', $exception->getMessage());
            }
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @return array{string, string, string, string} */
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
            $directory.'/fail-migration',
        ];
    }

    private function writeArtisanFixture(string $artisanPath, string $logPath, string $failurePath): void
    {
        $script = sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

file_put_contents(%s, implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);

if (($argv[1] ?? null) === 'migrate' && is_file(%s)) {
    fwrite(STDERR, 'test-only-sensitive-output');
    exit(17);
}
PHP,
            var_export($logPath, true),
            var_export($failurePath, true),
        );

        file_put_contents($artisanPath, $script);
    }

    private function cleanup(string $directory): void
    {
        foreach (['fail-migration', 'commands.log', 'artisan.php'] as $file) {
            @unlink($directory.'/'.$file);
        }

        @rmdir($directory);
    }
}
