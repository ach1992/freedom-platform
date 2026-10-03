<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;

final class ReleasePackageBuilderTest extends TestCase
{
    public function test_rc_builder_is_reproducible_and_emits_only_runtime_payload(): void
    {
        $root = storage_path('framework/testing/release-builder-'.bin2hex(random_bytes(4)));
        mkdir($root, 0700, true);

        try {
            $result = $this->runCommand([
                PHP_BINARY,
                base_path('scripts/release/build-update-package.php'),
                base_path('release/0.9.0-rc.2.json'),
                $root,
            ]);
            $decoded = json_decode($result, true, 32, JSON_THROW_ON_ERROR);

            self::assertSame('0.9.0-rc.2', $decoded['release_id'] ?? null);
            self::assertSame($decoded['package_sha256'] ?? null, $decoded['reproducible_rebuild_sha256'] ?? null);
            self::assertSame('PharUpdatePackageVerifier', $decoded['verifier'] ?? null);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', (string) ($decoded['source_commit'] ?? ''));
            self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', (string) ($decoded['source_tree'] ?? ''));
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) ($decoded['package_sha256'] ?? ''));

            $package = $root.'/'.($decoded['package'] ?? '');
            self::assertFileExists($package);
            self::assertSame($decoded['package_sha256'], hash_file('sha256', $package));

            $archive = new PharData($package);
            $paths = [];
            foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                $path = str_replace('\\', '/', $entry->getPathName());
                $prefix = 'phar://'.str_replace('\\', '/', $package).'/';
                if (! str_starts_with($path, $prefix)) {
                    throw new RuntimeException('Release archive test path could not be resolved.');
                }
                $paths[] = substr($path, strlen($prefix));
            }

            self::assertContains('release-manifest.json', $paths);
            self::assertContains('release-checksums.json', $paths);
            self::assertContains('RELEASE_NOTES.md', $paths);
            self::assertContains('artisan', $paths);
            self::assertContains('composer.lock', $paths);
            self::assertContains('public/index.php', $paths);

            foreach ($paths as $path) {
                self::assertFalse($path === '.env' || str_starts_with($path, 'storage/'));
                self::assertFalse($path === 'vendor' || str_starts_with($path, 'vendor/'));
                self::assertFalse($path === 'tests' || str_starts_with($path, 'tests/'));
                self::assertFalse($path === '.github' || str_starts_with($path, '.github/'));
                self::assertFalse($path === 'evidence' || str_starts_with($path, 'evidence/'));
                self::assertFalse($path === 'scripts' || str_starts_with($path, 'scripts/'));
                self::assertFalse($path === 'release' || str_starts_with($path, 'release/'));
            }
        } finally {
            $this->removeTree($root);
        }
    }

    public function test_release_package_is_reproducible_across_process_umasks(): void
    {
        $root = storage_path('framework/testing/release-builder-umask-'.bin2hex(random_bytes(4)));
        $permissive = $root.'/permissive';
        $restrictive = $root.'/restrictive';
        mkdir($permissive, 0700, true);
        mkdir($restrictive, 0700, true);
        $originalUmask = umask();

        try {
            umask(0002);
            $first = json_decode($this->runCommand([
                PHP_BINARY,
                base_path('scripts/release/build-update-package.php'),
                base_path('release/1.0.0.json'),
                $permissive,
            ]), true, 32, JSON_THROW_ON_ERROR);

            umask(0027);
            $second = json_decode($this->runCommand([
                PHP_BINARY,
                base_path('scripts/release/build-update-package.php'),
                base_path('release/1.0.0.json'),
                $restrictive,
            ]), true, 32, JSON_THROW_ON_ERROR);

            self::assertSame($first['package_sha256'] ?? null, $second['package_sha256'] ?? null);
            self::assertSame($first['manifest_sha256'] ?? null, $second['manifest_sha256'] ?? null);
            self::assertSame($first['checksums_sha256'] ?? null, $second['checksums_sha256'] ?? null);
        } finally {
            umask($originalUmask);
            $this->removeTree($root);
        }
    }

    public function test_final_release_metadata_targets_rc2_predecessor(): void
    {
        $metadata = json_decode(
            (string) file_get_contents(base_path('release/1.0.0.json')),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('1.0.0', $metadata['release_id'] ?? null);
        self::assertSame('0.9.0-rc.2', $metadata['upgrade']['from_release'] ?? null);
        self::assertSame('0.9.0-rc.2', $metadata['upgrade']['from_application_version'] ?? null);
        self::assertSame(
            $metadata['upgrade']['from_schema_sha256'] ?? null,
            $metadata['upgrade']['to_schema_sha256'] ?? null,
        );
    }

    /** @param list<string> $command */
    private function runCommand(array $command): string
    {
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path(), null, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            throw new RuntimeException('Release builder test process could not be started.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, is_string($stderr) ? $stderr : 'Release builder failed.');
        self::assertIsString($stdout);

        return $stdout;
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
