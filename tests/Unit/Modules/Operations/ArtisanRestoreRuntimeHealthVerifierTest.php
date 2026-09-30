<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\ArtisanRestoreRuntimeHealthVerifier;
use RuntimeException;
use Tests\TestCase;

final class ArtisanRestoreRuntimeHealthVerifierTest extends TestCase
{
    /** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
    public function test_health_check_runs_in_fresh_process_without_inherited_application_environment(): void
    {
        $directory = $this->directory('success');
        $binary = $this->fakeBinary($directory);
        $applicationEnvironment = [
            'TELEGRAM_LIFECYCLE_DB_PASSWORD' => 'test-only-lifecycle-secret',
            'DB_PASSWORD' => 'test-only-db-secret',
            'REDIS_PASSWORD' => 'test-only-redis-secret',
            'DB_HOST' => 'pre-restore-db.internal',
            'APP_ENV' => 'pre-restore-environment',
        ];

        foreach ($applicationEnvironment as $name => $value) {
            $this->environment($name, $value);
        }

        try {
            (new ArtisanRestoreRuntimeHealthVerifier(
                $binary,
                base_path('artisan'),
                $directory,
                30,
            ))->verify(str_repeat('a', 64));

            $arguments = json_decode(
                (string) file_get_contents($directory.'/health-args.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            self::assertSame([
                base_path('artisan'),
                'operations:restore-runtime-attest',
                '--expected-authority-fingerprint='.str_repeat('a', 64),
                '--json',
                '--no-ansi',
                '--no-interaction',
            ], $arguments);
            $environment = json_decode(
                (string) file_get_contents($directory.'/health-environment.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertSame([
                'TELEGRAM_LIFECYCLE_DB_PASSWORD' => 'unset',
                'DB_PASSWORD' => 'unset',
                'REDIS_PASSWORD' => 'unset',
                'DB_HOST' => 'unset',
                'APP_ENV' => 'unset',
            ], $environment);
        } finally {
            foreach (array_keys($applicationEnvironment) as $name) {
                $this->clearEnvironment($name);
            }
            $this->removeTree($directory);
        }
    }

    /** @requirement UPD-001 BAK-002 OPS-001 SEC-001 QUA-001 */
    public function test_update_recovery_attests_the_exact_predecessor_release_runtime(): void
    {
        $directory = $this->directory('exact-release');
        $binary = $this->fakeBinary($directory);
        $release = $directory.'/release-1.0.0';
        mkdir($release, 0700, true);
        file_put_contents($release.'/artisan', "<?php\n");
        chmod($release.'/artisan', 0600);

        try {
            (new ArtisanRestoreRuntimeHealthVerifier(
                $binary,
                base_path('artisan'),
                $directory,
                30,
            ))->verify(str_repeat('d', 64), $release);

            $arguments = json_decode(
                (string) file_get_contents($release.'/health-args.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertSame([
                $release.'/artisan',
                'operations:restore-runtime-attest',
                '--expected-authority-fingerprint='.str_repeat('d', 64),
                '--json',
                '--no-ansi',
                '--no-interaction',
            ], $arguments);
        } finally {
            $this->removeTree($directory);
        }
    }

    /** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
    public function test_child_dotenv_cannot_use_parent_database_or_redis_credentials(): void
    {
        $directory = $this->directory('dotenv-isolation');
        $artisan = $directory.'/artisan';
        $autoload = var_export(base_path('vendor/autoload.php'), true);

        file_put_contents(
            $directory.'/.env',
            "DB_PASSWORD=restored-invalid-db\nREDIS_PASSWORD=restored-invalid-redis\n",
        );
        file_put_contents(
            $artisan,
            "<?php\nrequire {$autoload};\n".
            "\\Dotenv\\Dotenv::createImmutable(__DIR__)->load();\n".
            "\$db = \$_ENV['DB_PASSWORD'] ?? null;\n".
            "\$redis = \$_ENV['REDIS_PASSWORD'] ?? null;\n".
            "exit(\$db === 'parent-valid-db' && \$redis === 'parent-valid-redis' ? 0 : 2);\n",
        );
        chmod($artisan, 0600);

        $this->environment('DB_PASSWORD', 'parent-valid-db');
        $this->environment('REDIS_PASSWORD', 'parent-valid-redis');

        try {
            try {
                (new ArtisanRestoreRuntimeHealthVerifier(
                    PHP_BINARY,
                    $artisan,
                    $directory,
                    30,
                ))->verify(str_repeat('c', 64));
                self::fail('Fresh attestation must not inherit valid pre-restore credentials.');
            } catch (RuntimeException $exception) {
                self::assertSame('The restored runtime health checks failed.', $exception->getMessage());
            }
        } finally {
            $this->clearEnvironment('DB_PASSWORD');
            $this->clearEnvironment('REDIS_PASSWORD');
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
                ))->verify(str_repeat('b', 64));
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
$environment = [];
foreach ([
    'TELEGRAM_LIFECYCLE_DB_PASSWORD',
    'DB_PASSWORD',
    'REDIS_PASSWORD',
    'DB_HOST',
    'APP_ENV',
] as $name) {
    $value = getenv($name);
    $environment[$name] = $value === false ? 'unset' : (string) $value;
}
file_put_contents(
    $directory.'/health-environment.json',
    json_encode($environment, JSON_THROW_ON_ERROR),
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
