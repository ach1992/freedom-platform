<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application;

use App\Shared\Application\ReleaseVersionResolver;
use RuntimeException;
use Tests\TestCase;

final class ReleaseVersionResolverTest extends TestCase
{
    public function test_missing_release_manifest_uses_environment_fallback(): void
    {
        $path = storage_path('framework/testing/missing-release-manifest-'.bin2hex(random_bytes(4)).'.json');

        self::assertSame('0.9.0-rc.2', ReleaseVersionResolver::resolve($path, '0.9.0-rc.2'));
        self::assertSame('0.0.0-dev', ReleaseVersionResolver::resolve($path, null));
    }

    public function test_verified_release_manifest_owns_runtime_application_version(): void
    {
        $path = $this->manifest([
            'version' => 1,
            'authority' => 'freedom_platform_release_v1',
            'release_id' => '1.0.0',
            'application_version' => '1.0.0',
        ]);

        try {
            self::assertSame('1.0.0', ReleaseVersionResolver::resolve($path, '0.9.0-rc.2'));
        } finally {
            @unlink($path);
        }
    }

    public function test_existing_invalid_or_symlink_manifest_fails_closed(): void
    {
        $invalid = $this->manifest([
            'version' => 1,
            'authority' => 'wrong-authority',
            'application_version' => '1.0.0',
        ]);

        try {
            ReleaseVersionResolver::resolve($invalid, '0.9.0-rc.2');
            self::fail('An existing invalid release manifest must not fall back to mutable environment version state.');
        } catch (RuntimeException $exception) {
            self::assertSame('The release version manifest authority is invalid.', $exception->getMessage());
        } finally {
            @unlink($invalid);
        }

        $target = $this->manifest([
            'version' => 1,
            'authority' => 'freedom_platform_release_v1',
            'application_version' => '1.0.0',
        ]);
        $link = $target.'.link';
        symlink($target, $link);

        try {
            ReleaseVersionResolver::resolve($link, '0.9.0-rc.2');
            self::fail('A symlink release manifest must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('The release version manifest authority is unsafe.', $exception->getMessage());
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function manifest(array $manifest): string
    {
        $path = storage_path('framework/testing/release-version-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $path;
    }
}
