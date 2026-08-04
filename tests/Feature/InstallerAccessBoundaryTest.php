<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Installer\Application\InstallerAccessTokenStore;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class InstallerAccessBoundaryTest extends TestCase
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function test_installer_is_not_discoverable_without_an_ssh_issued_access_file(): void
    {
        config()->set('installer.access_file', storage_path('framework/testing/missing-installer-access.json'));
        config()->set('installer.lock_path', storage_path('framework/testing/missing-installer-lock'));

        $this->get('/installer/unlock')->assertNotFound();
    }

    public function test_production_installer_rejects_plain_http_before_token_entry(): void
    {
        $path = storage_path('framework/testing/installer-http-token.json');
        @unlink($path);

        $this->app['env'] = 'production';
        config()->set('installer.access_file', $path);
        config()->set('installer.lock_path', storage_path('framework/testing/missing-installer-lock'));

        $this->accessStore($path)->issue(5);

        try {
            $this->get('http://example.test/installer/unlock')->assertNotFound();
            $this->get('https://example.test/installer/unlock')->assertOk();
        } finally {
            $this->app['env'] = 'testing';
            @unlink($path);
        }
    }

    /** @requirement INS-001 SEC-004 SEC-007 QUA-011 */
    public function test_unlocked_preflight_renders_operational_checks_without_exposing_remote_content(): void
    {
        $path = storage_path('framework/testing/installer-preflight-token.json');
        $lockPath = storage_path('framework/testing/installer-preflight-lock.json');
        @unlink($path);
        @unlink($lockPath);

        config()->set('installer.access_file', $path);
        config()->set('installer.lock_path', $lockPath);
        config()->set('installer.environment', [
            'paths' => [storage_path(), base_path('bootstrap/cache')],
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
        Http::fake([
            'https://repo.packagist.org/*' => Http::response('private-provider-response', 204),
            'https://api.telegram.org/*' => Http::response('private-provider-response', 401),
        ]);
        $this->accessStore($path)->issue(5);

        try {
            $response = $this->withSession([
                'installer.unlocked_until' => now()->addMinute()->getTimestamp(),
            ])->get('/installer/preflight');

            $response->assertOk();
            $response->assertSee(__('installer.checks.outbound_https'));
            $response->assertSee(__('installer.checks.disk_space'));
            $response->assertSee(__('installer.checks.ownership'));
            $response->assertDontSee('private-provider-response');
        } finally {
            @unlink($path);
            @unlink($lockPath);
        }
    }

    private function accessStore(string $path): InstallerAccessTokenStore
    {
        return new InstallerAccessTokenStore(
            new class implements Clock
            {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-03T00:00:00+00:00');
                }
            },
            new class implements RandomGenerator
            {
                public function bytes(int $length): string
                {
                    return str_repeat("\x01", $length);
                }

                public function integer(int $minimum, int $maximum): int
                {
                    return $minimum;
                }
            },
            $path,
        );
    }
}
