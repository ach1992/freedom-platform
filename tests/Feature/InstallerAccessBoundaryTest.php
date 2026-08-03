<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Installer\Application\InstallerAccessTokenStore;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
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

        $store = new InstallerAccessTokenStore(
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
        $store->issue(5);

        try {
            $this->get('http://example.test/installer/unlock')->assertNotFound();
            $this->get('https://example.test/installer/unlock')->assertOk();
        } finally {
            $this->app['env'] = 'testing';
            @unlink($path);
        }
    }
}
