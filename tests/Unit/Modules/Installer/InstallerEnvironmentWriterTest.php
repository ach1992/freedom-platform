<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerEnvironmentWriter;
use App\Shared\Application\RandomGenerator;
use RuntimeException;
use Tests\TestCase;

final class InstallerEnvironmentWriterTest extends TestCase
{
    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_creates_a_private_environment_generates_an_app_key_and_commits_the_snapshot(): void
    {
        [$directory, $environmentPath, $snapshotPath] = $this->paths('create');
        $writer = $this->writer($environmentPath, $snapshotPath);
        $testValue = 'test-only-$value-with-"quotes"';

        try {
            $result = $writer->write([
                'DB_HOST' => '127.0.0.1',
                'DB_PASSWORD' => $testValue,
            ]);

            $contents = (string) file_get_contents($environmentPath);

            $this->assertTrue($result['created']);
            $this->assertTrue($result['generated_app_key']);
            $this->assertSame(['APP_KEY', 'DB_HOST', 'DB_PASSWORD'], $result['changed_keys']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['snapshot_checksum']);
            $this->assertStringContainsString('APP_KEY="base64:'.base64_encode(str_repeat("\x02", 32)).'"', $contents);
            $this->assertStringContainsString('DB_HOST="127.0.0.1"', $contents);
            $this->assertStringContainsString('DB_PASSWORD="test-only-\\$value-with-\\"quotes\\""', $contents);
            $this->assertSame(0600, fileperms($environmentPath) & 0777);
            $this->assertFileExists($snapshotPath);
            $this->assertFileExists($snapshotPath.'.json');
            $this->assertStringNotContainsString($testValue, json_encode($result, JSON_THROW_ON_ERROR));

            $writer->commit();

            $this->assertFileDoesNotExist($snapshotPath);
            $this->assertFileDoesNotExist($snapshotPath.'.json');
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_accepts_dedicated_metadata_credentials_without_disclosing_the_secret_in_result(): void
    {
        [$directory, $environmentPath, $snapshotPath] = $this->paths('telegram-metadata');
        $allowedKeys = config('installer.environment.allowed_keys');
        $nonPersistableKeys = config('installer.environment.non_persistable_keys');
        $this->assertIsArray($allowedKeys);
        $this->assertIsArray($nonPersistableKeys);
        $this->assertContains('TELEGRAM_METADATA_DB_URL', $allowedKeys);
        $this->assertContains('TELEGRAM_METADATA_DB_USERNAME', $allowedKeys);
        $this->assertContains('TELEGRAM_METADATA_DB_PASSWORD', $allowedKeys);
        $this->assertNotContains('TELEGRAM_LIFECYCLE_DB_USERNAME', $allowedKeys);
        $this->assertNotContains('TELEGRAM_LIFECYCLE_DB_PASSWORD', $allowedKeys);
        $this->assertContains('TELEGRAM_LIFECYCLE_DB_PASSWORD', $nonPersistableKeys);

        $writer = new InstallerEnvironmentWriter(
            new class implements RandomGenerator
            {
                public function bytes(int $length): string
                {
                    return str_repeat("\x03", $length);
                }

                public function integer(int $minimum, int $maximum): int
                {
                    return $minimum;
                }
            },
            $environmentPath,
            $snapshotPath,
            $allowedKeys,
            $nonPersistableKeys,
        );
        $secret = 'metadata-test-only-$secret-with-"quotes"';

        try {
            $result = $writer->write([
                'TELEGRAM_METADATA_DB_URL' => 'mysql://metadata.internal:3306/information_schema',
                'TELEGRAM_METADATA_DB_USERNAME' => 'telegram_metadata',
                'TELEGRAM_METADATA_DB_PASSWORD' => $secret,
            ]);
            $contents = (string) file_get_contents($environmentPath);

            $this->assertStringContainsString(
                'TELEGRAM_METADATA_DB_URL="mysql://metadata.internal:3306/information_schema"',
                $contents,
            );
            $this->assertStringContainsString('TELEGRAM_METADATA_DB_USERNAME="telegram_metadata"', $contents);
            $this->assertStringContainsString(
                'TELEGRAM_METADATA_DB_PASSWORD="metadata-test-only-\\$secret-with-\\"quotes\\""',
                $contents,
            );
            $this->assertStringNotContainsString($secret, json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(0600, fileperms($environmentPath) & 0777);
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_preserves_unrelated_lines_deduplicates_managed_keys_and_restores_exact_contents(): void
    {
        [$directory, $environmentPath, $snapshotPath] = $this->paths('rollback');
        $original = <<<'ENV'
# retained comment
CUSTOM_SETTING=keep-me
DB_HOST=old-primary
APP_KEY=base64:existing-test-key
DB_HOST=old-duplicate
ENV;
        $original .= "\n";
        $this->writeFixture($environmentPath, $original);
        $writer = $this->writer($environmentPath, $snapshotPath);

        try {
            $result = $writer->write([
                'APP_NAME' => 'Freedom Platform',
                'DB_HOST' => 'database.internal',
            ]);
            $updated = (string) file_get_contents($environmentPath);

            $this->assertFalse($result['created']);
            $this->assertFalse($result['generated_app_key']);
            $this->assertStringContainsString('# retained comment', $updated);
            $this->assertStringContainsString('CUSTOM_SETTING=keep-me', $updated);
            $this->assertStringContainsString('APP_KEY=base64:existing-test-key', $updated);
            $this->assertStringContainsString('APP_NAME="Freedom Platform"', $updated);
            $this->assertSame(1, substr_count($updated, 'DB_HOST='));
            $this->assertStringContainsString('DB_HOST="database.internal"', $updated);

            $writer->rollback();

            $this->assertSame($original, file_get_contents($environmentPath));
            $this->assertFileDoesNotExist($snapshotPath);
            $this->assertFileDoesNotExist($snapshotPath.'.json');
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_rejects_a_preexisting_configured_deployment_only_secret_before_snapshot_or_write(): void
    {
        [$directory, $environmentPath, $snapshotPath] = $this->paths('preexisting-lifecycle-secret');
        $secret = 'test-only-preexisting-lifecycle-secret';
        $original = "APP_KEY=base64:existing-test-key\nTELEGRAM_LIFECYCLE_DB_PASSWORD=\"\"\nTELEGRAM_LIFECYCLE_DB_PASSWORD=\"{$secret}\"\nDB_HOST=old-primary\n";
        $this->writeFixture($environmentPath, $original);
        $writer = $this->writer($environmentPath, $snapshotPath);

        try {
            try {
                $writer->write(['DB_HOST' => 'database.internal']);
                $this->fail('A configured deployment-only lifecycle secret must block installer environment persistence.');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
                $this->assertStringContainsString('deployment-only key', $exception->getMessage());
            }

            $this->assertSame($original, file_get_contents($environmentPath));
            $this->assertFileDoesNotExist($snapshotPath);
            $this->assertFileDoesNotExist($snapshotPath.'.json');
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_blank_deployment_only_placeholder_does_not_block_safe_environment_updates(): void
    {
        [$directory, $environmentPath, $snapshotPath] = $this->paths('blank-lifecycle-placeholder');
        $this->writeFixture(
            $environmentPath,
            "APP_KEY=base64:existing-test-key\nTELEGRAM_LIFECYCLE_DB_PASSWORD=\"\"\nDB_HOST=old-primary\n",
        );
        $writer = $this->writer($environmentPath, $snapshotPath);

        try {
            $writer->write(['DB_HOST' => 'database.internal']);
            $contents = (string) file_get_contents($environmentPath);

            $this->assertStringContainsString('TELEGRAM_LIFECYCLE_DB_PASSWORD=""', $contents);
            $this->assertStringContainsString('DB_HOST="database.internal"', $contents);
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_rejects_unknown_keys_and_control_characters_without_disclosing_values(): void
    {
        [$directory, $environmentPath, $snapshotPath] = $this->paths('validation');
        $writer = $this->writer($environmentPath, $snapshotPath);
        $testValue = "test-only-value\nINJECTED=true";

        try {
            foreach ([
                ['UNAPPROVED_KEY' => 'test-only-value'],
                ['DB_PASSWORD' => $testValue],
            ] as $values) {
                try {
                    $writer->write($values);
                    $this->fail('Invalid installer environment input must be rejected.');
                } catch (RuntimeException $exception) {
                    $this->assertStringNotContainsString('test-only-value', $exception->getMessage());
                    $this->assertStringNotContainsString('INJECTED', $exception->getMessage());
                }
            }

            $this->assertFileDoesNotExist($environmentPath);
            $this->assertFileDoesNotExist($snapshotPath);
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @return array{string, string, string} */
    private function paths(string $case): array
    {
        $directory = storage_path('framework/testing/installer-environment-writer-'.$case.'-'.bin2hex(random_bytes(4)));

        return [$directory, $directory.'/.env', $directory.'/rollback/environment.snapshot'];
    }

    private function writer(string $environmentPath, string $snapshotPath): InstallerEnvironmentWriter
    {
        return new InstallerEnvironmentWriter(
            new class implements RandomGenerator
            {
                public function bytes(int $length): string
                {
                    return str_repeat("\x02", $length);
                }

                public function integer(int $minimum, int $maximum): int
                {
                    return $minimum;
                }
            },
            $environmentPath,
            $snapshotPath,
            ['APP_KEY', 'APP_NAME', 'DB_HOST', 'DB_PASSWORD'],
            ['TELEGRAM_LIFECYCLE_DB_PASSWORD'],
        );
    }

    private function writeFixture(string $path, string $contents): void
    {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            $this->fail('Could not create environment fixture directory.');
        }

        file_put_contents($path, $contents);
    }

    private function cleanup(string $directory): void
    {
        $paths = [
            $directory.'/rollback/environment.snapshot.json',
            $directory.'/rollback/environment.snapshot.lock',
            $directory.'/rollback/environment.snapshot',
            $directory.'/.env',
        ];

        foreach ($paths as $path) {
            @unlink($path);
        }

        @rmdir($directory.'/rollback');
        @rmdir($directory);
    }
}
