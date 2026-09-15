<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\ArtisanReleaseHealthVerifier;
use RuntimeException;
use Tests\TestCase;

final class ArtisanReleaseHealthVerifierTest extends TestCase
{
    /** @requirement RUN-001 RUN-002 RUN-003 SEC-008 SEC-010 QUA-011 */
    public function test_it_runs_only_the_fixed_redacted_critical_health_command(): void
    {
        [$directory, $artisan, $arguments, $failure] = $this->fixture('success');
        $this->writeArtisan($artisan, $arguments, $failure);
        $verifier = new ArtisanReleaseHealthVerifier(PHP_BINARY, 5);

        try {
            $verifier->verify($directory);

            $this->assertSame(
                'health:check --critical --json --redact --no-ansi --no-interaction',
                trim((string) file_get_contents($arguments)),
            );
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement RUN-001 RUN-002 RUN-003 SEC-008 SEC-010 QUA-011 */
    public function test_failure_output_is_not_exposed(): void
    {
        [$directory, $artisan, $arguments, $failure] = $this->fixture('failure');
        $this->writeArtisan($artisan, $arguments, $failure);
        file_put_contents($failure, 'fail');
        $verifier = new ArtisanReleaseHealthVerifier(PHP_BINARY, 5);

        try {
            try {
                $verifier->verify($directory);
                $this->fail('A failed release health command must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The activated release failed health verification.', $exception->getMessage());
                $this->assertStringNotContainsString('test-only-health-secret', $exception->getMessage());
            }
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @return array{string, string, string, string} */
    private function fixture(string $case): array
    {
        $directory = storage_path('framework/testing/release-health-'.$case.'-'.bin2hex(random_bytes(4)));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Could not create release health fixture directory.');
        }

        return [
            $directory,
            $directory.'/artisan',
            $directory.'/arguments.log',
            $directory.'/failure.flag',
        ];
    }

    private function writeArtisan(string $artisan, string $arguments, string $failure): void
    {
        $script = sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

file_put_contents(%s, implode(' ', array_slice($argv, 1)));

if (is_file(%s)) {
    fwrite(STDERR, 'test-only-health-secret');
    exit(19);
}
PHP,
            var_export($arguments, true),
            var_export($failure, true),
        );

        file_put_contents($artisan, $script);
    }

    private function cleanup(string $directory): void
    {
        foreach (['failure.flag', 'arguments.log', 'artisan'] as $file) {
            @unlink($directory.'/'.$file);
        }

        @rmdir($directory);
    }
}
