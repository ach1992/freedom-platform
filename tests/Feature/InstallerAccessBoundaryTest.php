<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Installer\Application\Contracts\InstallerFinalizationRunner;
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

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_unlocked_finalization_writes_environment_runs_fixed_steps_and_returns_no_secrets(): void
    {
        $paths = $this->finalizationPaths('success');
        $runner = new HttpRecordingFinalizationRunner;
        $this->configureFinalization($paths, $runner);
        $this->accessStore($paths['access'])->issue(5);
        $testSecret = 'test-only-database-secret';
        $lifecycleSecret = 'test-only-lifecycle-database-secret';

        try {
            $response = $this->withServerVariables([
                'REMOTE_ADDR' => '198.51.100.10',
            ])->withSession([
                'installer.unlocked_until' => now()->addMinute()->getTimestamp(),
            ])->postJson('/installer/finalize', [
                'environment' => [
                    'DB_HOST' => 'database.internal',
                    'DB_PASSWORD' => $testSecret,
                ],
                'lifecycle_database_password' => $lifecycleSecret,
            ]);

            $response->assertOk()->assertExactJson(['status' => 'completed']);
            $response->assertDontSee($testSecret);
            $response->assertDontSee($lifecycleSecret);
            $this->assertSame($lifecycleSecret, $runner->migrationPassword);
            $this->assertSame(['config_clear', 'migrations', 'config_cache'], $runner->actions);
            $this->assertStringContainsString('DB_PASSWORD="'.$testSecret.'"', (string) file_get_contents($paths['environment']));
            $this->assertStringNotContainsString($lifecycleSecret, (string) file_get_contents($paths['environment']));
            $this->assertFileExists($paths['lock']);
            $this->assertFileDoesNotExist($paths['snapshot']);
        } finally {
            $this->cleanupFinalization($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_lifecycle_password_is_not_flashed_to_session_on_browser_validation_failure(): void
    {
        $paths = $this->finalizationPaths('lifecycle-validation-flash');
        $runner = new HttpRecordingFinalizationRunner;
        $this->configureFinalization($paths, $runner);
        $this->accessStore($paths['access'])->issue(5);
        $marker = 'test-only-lifecycle-browser-secret-'.bin2hex(random_bytes(8));

        try {
            $response = $this->withServerVariables([
                'REMOTE_ADDR' => '198.51.100.13',
            ])->withSession([
                'installer.unlocked_until' => now()->addMinute()->getTimestamp(),
            ])->post('/installer/finalize', [
                'environment' => [],
                'lifecycle_database_password' => $marker,
            ]);

            $response->assertRedirect();
            $response->assertSessionHasErrors('environment');
            $response->assertDontSee($marker);
            $this->assertSame([], $runner->actions);
            $this->assertFileDoesNotExist($paths['environment']);
            $this->assertFileDoesNotExist($paths['lock']);

            $oldInput = session()->get('_old_input', []);
            $this->assertIsArray($oldInput);
            $this->assertArrayNotHasKey('lifecycle_database_password', $oldInput);
            $this->assertStringNotContainsString(
                $marker,
                json_encode(session()->all(), JSON_THROW_ON_ERROR),
            );

            foreach (glob(storage_path('logs/*')) ?: [] as $logPath) {
                if (is_file($logPath)) {
                    $this->assertStringNotContainsString($marker, (string) file_get_contents($logPath));
                }
            }
        } finally {
            $this->cleanupFinalization($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_lifecycle_password_cannot_be_smuggled_into_the_persisted_environment_map(): void
    {
        $paths = $this->finalizationPaths('lifecycle-environment-rejected');
        $runner = new HttpRecordingFinalizationRunner;
        $this->configureFinalization($paths, $runner);
        $this->accessStore($paths['access'])->issue(5);
        $testSecret = 'test-only-lifecycle-environment-secret';

        try {
            $response = $this->withServerVariables([
                'REMOTE_ADDR' => '198.51.100.12',
            ])->withSession([
                'installer.unlocked_until' => now()->addMinute()->getTimestamp(),
            ])->postJson('/installer/finalize', [
                'environment' => ['TELEGRAM_LIFECYCLE_DB_PASSWORD' => $testSecret],
            ]);

            $response->assertUnprocessable();
            $response->assertDontSee($testSecret);
            $this->assertSame([], $runner->actions);
            $this->assertFileDoesNotExist($paths['environment']);
            $this->assertFileDoesNotExist($paths['lock']);
        } finally {
            $this->cleanupFinalization($paths);
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_finalization_rejects_unapproved_keys_without_disclosing_or_writing_values(): void
    {
        $paths = $this->finalizationPaths('invalid');
        $runner = new HttpRecordingFinalizationRunner;
        $this->configureFinalization($paths, $runner);
        $this->accessStore($paths['access'])->issue(5);
        $testSecret = 'test-only-unapproved-secret';

        try {
            $response = $this->withServerVariables([
                'REMOTE_ADDR' => '198.51.100.11',
            ])->withSession([
                'installer.unlocked_until' => now()->addMinute()->getTimestamp(),
            ])->postJson('/installer/finalize', [
                'environment' => ['UNAPPROVED_KEY' => $testSecret],
            ]);

            $response->assertUnprocessable();
            $response->assertDontSee($testSecret);
            $this->assertSame([], $runner->actions);
            $this->assertFileDoesNotExist($paths['environment']);
            $this->assertFileDoesNotExist($paths['lock']);
        } finally {
            $this->cleanupFinalization($paths);
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

    /** @return array{directory: string, access: string, lock: string, journal: string, environment: string, snapshot: string} */
    private function finalizationPaths(string $case): array
    {
        $directory = storage_path('framework/testing/installer-http-finalization-'.$case.'-'.bin2hex(random_bytes(4)));

        return [
            'directory' => $directory,
            'access' => $directory.'/private/access.json',
            'lock' => $directory.'/private/installer.lock',
            'journal' => $directory.'/private/bootstrap-journal.json',
            'environment' => $directory.'/.env',
            'snapshot' => $directory.'/private/environment.snapshot',
        ];
    }

    /** @param  array{access: string, lock: string, journal: string, environment: string, snapshot: string}  $paths */
    private function configureFinalization(array $paths, InstallerFinalizationRunner $runner): void
    {
        config()->set('installer.access_file', $paths['access']);
        config()->set('installer.lock_path', $paths['lock']);
        config()->set('installer.bootstrap_journal_path', $paths['journal']);
        config()->set('installer.environment.file_path', $paths['environment']);
        config()->set('installer.environment.snapshot_path', $paths['snapshot']);
        config()->set('installer.environment.allowed_keys', ['APP_KEY', 'DB_HOST', 'DB_PASSWORD']);
        $this->app->instance(InstallerFinalizationRunner::class, $runner);
    }

    /** @param  array{directory: string, access: string, lock: string, journal: string, environment: string, snapshot: string}  $paths */
    private function cleanupFinalization(array $paths): void
    {
        foreach ([
            $paths['snapshot'].'.json',
            $paths['snapshot'].'.lock',
            $paths['snapshot'],
            $paths['journal'],
            $paths['lock'],
            $paths['access'],
            $paths['environment'],
        ] as $path) {
            @unlink($path);
        }

        @rmdir($paths['directory'].'/private');
        @rmdir($paths['directory']);
    }
}

final class HttpRecordingFinalizationRunner implements InstallerFinalizationRunner
{
    /** @var list<string> */
    public array $actions = [];

    public ?string $migrationPassword = null;

    public function clearConfiguration(): void
    {
        $this->actions[] = 'config_clear';
    }

    public function migrate(?string $lifecycleDatabasePassword = null): void
    {
        $this->migrationPassword = $lifecycleDatabasePassword;
        $this->actions[] = 'migrations';
    }

    public function cacheConfiguration(): void
    {
        $this->actions[] = 'config_cache';
    }
}
