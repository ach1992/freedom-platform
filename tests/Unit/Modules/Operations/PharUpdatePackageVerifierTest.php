<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\UpdateMigrationIdentity;
use App\Modules\Operations\Infrastructure\PharUpdatePackageVerifier;
use PharData;
use RuntimeException;
use Tests\TestCase;

final class PharUpdatePackageVerifierTest extends TestCase
{
    /** @requirement UPD-001 RUN-006 SEC-001 SEC-008 QUA-001 */
    public function test_it_verifies_complete_trusted_package_and_extracts_exact_payload(): void
    {
        $fixture = $this->fixture('valid');

        try {
            [$packagePath, $sha256, $targetSchema] = $this->package($fixture);
            $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

            $verified = $verifier->verify($packagePath, $sha256);
            self::assertSame('1.1.0', $verified->releaseId);
            self::assertSame('1.0.0', $verified->fromRelease);
            self::assertSame('1.0.0', $verified->fromApplicationVersion);
            self::assertSame($targetSchema, $verified->toSchemaSha256);
            self::assertTrue($verified->previousCodeCompatibleWith($targetSchema));

            $destination = $fixture.'/release';
            $verifier->extract($verified, $destination);

            self::assertFileExists($destination.'/artisan');
            self::assertFileExists($destination.'/release-manifest.json');
            self::assertFileExists($destination.'/release-checksums.json');
            self::assertFileExists($destination.'/database/migrations/2026_01_01_000000_test.php');
            self::assertFileDoesNotExist($destination.'/.env');
        } finally {
            $this->removeTree($fixture);
        }
    }

    /** @requirement UPD-001 SEC-001 QUA-001 */
    public function test_it_rejects_wrong_complete_package_trusted_checksum_before_archive_use(): void
    {
        $fixture = $this->fixture('trusted-checksum');

        try {
            [$packagePath] = $this->package($fixture);
            $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('trusted checksum verification failed');
            $verifier->verify($packagePath, str_repeat('0', 64));
        } finally {
            $this->removeTree($fixture);
        }
    }

    /** @requirement UPD-001 RUN-006 SEC-001 SEC-008 QUA-001 */
    public function test_it_rejects_tampered_per_file_checksum_and_checksum_manifest_binding(): void
    {
        $fixture = $this->fixture('file-checksum');

        try {
            [$packagePath, $sha256] = $this->package($fixture, mutateChecksums: static function (array $checksums): array {
                $checksums['files']['artisan'] = str_repeat('f', 64);

                return $checksums;
            });
            $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('release file checksum verification failed');
            $verifier->verify($packagePath, $sha256);
        } finally {
            $this->removeTree($fixture);
        }
    }

    /** @requirement UPD-001 SEC-008 QUA-001 */
    public function test_it_rejects_path_traversal_declared_by_package(): void
    {
        $fixture = $this->fixture('traversal');

        try {
            [$packagePath, $sha256] = $this->package($fixture, mutateChecksums: static function (array $checksums): array {
                $checksums['files']['../outside.php'] = str_repeat('a', 64);

                return $checksums;
            });
            $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('path traversal');
            $verifier->verify($packagePath, $sha256);
        } finally {
            $this->removeTree($fixture);
        }
    }

    /** @requirement UPD-001 SEC-008 QUA-001 */
    public function test_it_rejects_symlink_entries_before_extraction(): void
    {
        $fixture = $this->fixture('symlink');

        try {
            $packagePath = $fixture.'/packages/release.tar';
            copy(base_path('tests/Fixtures/Operations/update-symlink.tar'), $packagePath);
            $sha256 = hash_file('sha256', $packagePath);
            self::assertIsString($sha256);
            $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsupported link or special entry');
            $verifier->verify($packagePath, $sha256);
        } finally {
            $this->removeTree($fixture);
        }
    }

    /** @requirement UPD-001 RUN-006 QUA-001 */
    public function test_it_rejects_incompatible_php_framework_and_schema_metadata(): void
    {
        foreach (['php', 'framework', 'schema'] as $case) {
            $fixture = $this->fixture('compat-'.$case);

            try {
                [$packagePath, $sha256] = $this->package(
                    $fixture,
                    mutateManifest: static function (array $manifest) use ($case): array {
                        if ($case === 'php') {
                            $manifest['runtime']['php_min'] = '99.0';
                            $manifest['runtime']['php_max_exclusive'] = '100.0';
                        } elseif ($case === 'framework') {
                            $manifest['framework']['laravel_major'] = 99;
                        } else {
                            $manifest['upgrade']['to_schema_sha256'] = str_repeat('b', 64);
                        }

                        return $manifest;
                    },
                );
                $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

                try {
                    $verifier->verify($packagePath, $sha256);
                    self::fail('Incompatible package metadata must fail closed.');
                } catch (RuntimeException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            } finally {
                $this->removeTree($fixture);
            }
        }
    }

    /** @requirement UPD-001 RUN-006 SEC-001 QUA-001 */
    public function test_it_rejects_unsupported_manifest_authority_and_ambiguous_rollback_metadata(): void
    {
        foreach (['authority', 'rollback'] as $case) {
            $fixture = $this->fixture('manifest-'.$case);

            try {
                [$packagePath, $sha256] = $this->package(
                    $fixture,
                    mutateManifest: static function (array $manifest) use ($case): array {
                        if ($case === 'authority') {
                            $manifest['authority'] = 'untrusted_release_authority';
                        } else {
                            $compatible = $manifest['rollback']['previous_code_compatible_schema_sha256'][0];
                            $manifest['rollback']['previous_code_compatible_schema_sha256'] = [$compatible, $compatible];
                        }

                        return $manifest;
                    },
                );
                $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

                try {
                    $verifier->verify($packagePath, $sha256);
                    self::fail('Unsupported manifest or rollback metadata must fail closed.');
                } catch (RuntimeException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            } finally {
                $this->removeTree($fixture);
            }
        }
    }

    /** @requirement UPD-001 SEC-008 QUA-001 */
    public function test_it_rejects_reserved_secret_and_mutable_runtime_payload_paths(): void
    {
        foreach (['.env', 'storage/private.txt', 'vendor/autoload.php'] as $reserved) {
            $fixture = $this->fixture('reserved-'.str_replace(['/', '.'], '-', $reserved));

            try {
                [$packagePath, $sha256] = $this->package($fixture, extraPayload: [$reserved => 'secret-material']);
                $verifier = new PharUpdatePackageVerifier($fixture.'/packages');

                try {
                    $verifier->verify($packagePath, $sha256);
                    self::fail('Reserved release payload paths must be refused.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('reserved payload path', $exception->getMessage());
                }
            } finally {
                $this->removeTree($fixture);
            }
        }
    }

    private function fixture(string $case): string
    {
        $root = storage_path('framework/testing/update-package-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($root.'/packages', 0700, true);

        return $root;
    }

    /**
     * @param  (callable(array<string,mixed>):array<string,mixed>)|null  $mutateManifest
     * @param  (callable(array<string,mixed>):array<string,mixed>)|null  $mutateChecksums
     * @param  array<string,string>  $extraPayload
     * @return array{string,string,string}
     */
    private function package(
        string $fixture,
        ?callable $mutateManifest = null,
        ?callable $mutateChecksums = null,
        array $extraPayload = [],
    ): array {
        $migration = '2026_01_01_000000_test';
        $payload = [
            'artisan' => "<?php\n",
            'composer.json' => "{\"name\":\"example/release\"}\n",
            'composer.lock' => json_encode([
                'packages' => [[
                    'name' => 'laravel/framework',
                    'version' => 'v13.8.0',
                ]],
            ], JSON_THROW_ON_ERROR),
            'public/index.php' => "<?php\n",
            'RELEASE_NOTES.md' => "Controlled test release.\n",
            'database/migrations/'.$migration.'.php' => "<?php\nreturn new class {};\n",
            ...$extraPayload,
        ];

        $files = [];
        foreach ($payload as $path => $contents) {
            $files[$path] = hash('sha256', $contents);
        }
        $targetSchema = UpdateMigrationIdentity::fromChecksums([
            $migration => $files['database/migrations/'.$migration.'.php'],
        ]);
        $checksums = [
            'version' => 1,
            'files' => $files,
        ];
        if ($mutateChecksums !== null) {
            $checksums = $mutateChecksums($checksums);
        }
        $checksumContents = json_encode($checksums, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $manifest = [
            'version' => 1,
            'authority' => 'freedom_platform_release_v1',
            'release_id' => '1.1.0',
            'application_version' => '1.1.0',
            'upgrade' => [
                'from_release' => '1.0.0',
                'from_application_version' => '1.0.0',
                'from_schema_sha256' => str_repeat('a', 64),
                'to_schema_sha256' => $targetSchema,
            ],
            'runtime' => [
                'php_min' => '8.4.0',
                'php_max_exclusive' => '9.0.0',
            ],
            'framework' => [
                'laravel_major' => 13,
            ],
            'composer_lock_sha256' => $files['composer.lock'],
            'checksums_sha256' => hash('sha256', $checksumContents),
            'required_free_bytes' => 1,
            'rollback' => [
                'previous_code_compatible_schema_sha256' => [$targetSchema],
            ],
        ];
        if ($mutateManifest !== null) {
            $manifest = $mutateManifest($manifest);
        }
        $manifestContents = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $path = $fixture.'/packages/release.tar';
        $archive = new PharData($path);
        foreach ($payload as $relative => $contents) {
            $archive->addFromString($relative, $contents);
        }
        $archive->addFromString('release-checksums.json', $checksumContents);
        $archive->addFromString('release-manifest.json', $manifestContents);
        unset($archive);

        $sha256 = hash_file('sha256', $path);
        self::assertIsString($sha256);

        return [$path, $sha256, $targetSchema];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
