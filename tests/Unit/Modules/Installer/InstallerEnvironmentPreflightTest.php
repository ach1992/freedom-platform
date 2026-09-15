<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerEnvironmentPreflight;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class InstallerEnvironmentPreflightTest extends TestCase
{
    /** @requirement INS-001 SEC-004 SEC-007 QUA-011 */
    public function test_it_validates_allowlisted_https_disk_permissions_and_optional_ownership(): void
    {
        $directory = $this->temporaryDirectory('passing');

        try {
            Http::fake([
                'https://repo.packagist.org/*' => Http::response('', 204),
                'https://api.telegram.org/*' => Http::response('', 401),
            ]);

            config()->set('installer.environment', [
                'paths' => [$directory],
                'minimum_free_bytes' => 1,
                'expected_owner' => null,
                'expected_group' => null,
                'outbound_urls' => [
                    'https://repo.packagist.org/packages.json',
                    'https://api.telegram.org',
                ],
                'outbound_allowed_hosts' => [
                    'repo.packagist.org',
                    'api.telegram.org',
                ],
                'connect_timeout_seconds' => 1,
                'timeout_seconds' => 1,
            ]);

            $this->assertSame([
                'outbound_https' => true,
                'disk_space' => true,
                'storage' => true,
                'ownership' => true,
            ], (new InstallerEnvironmentPreflight)->checks());
            Http::assertSentCount(2);
        } finally {
            $this->cleanup($directory);
        }
    }

    /** @requirement INS-001 SEC-004 SEC-007 QUA-011 */
    public function test_it_fails_closed_for_unapproved_urls_missing_paths_and_unknown_owners(): void
    {
        Http::fake();

        config()->set('installer.environment', [
            'paths' => [storage_path('framework/testing/missing-installer-path')],
            'minimum_free_bytes' => PHP_INT_MAX,
            'expected_owner' => 'missing-installer-owner',
            'expected_group' => 'missing-installer-group',
            'outbound_urls' => ['http://127.0.0.1/internal'],
            'outbound_allowed_hosts' => ['127.0.0.1'],
            'connect_timeout_seconds' => 1,
            'timeout_seconds' => 1,
        ]);

        $this->assertSame([
            'outbound_https' => false,
            'disk_space' => false,
            'storage' => false,
            'ownership' => false,
        ], (new InstallerEnvironmentPreflight)->checks());
        Http::assertNothingSent();
    }

    private function temporaryDirectory(string $case): string
    {
        $directory = storage_path('framework/testing/installer-environment-'.$case.'-'.bin2hex(random_bytes(4)));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Could not create the installer environment test directory.');
        }

        return $directory;
    }

    private function cleanup(string $directory): void
    {
        @rmdir($directory);
    }
}
