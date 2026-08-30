<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Operations\Application\RuntimeHealthProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class HealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_health_check_passes_with_required_services(): void
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('health:check', ['--critical' => true, '--json' => true], $output);
        $content = $output->fetch();

        self::assertSame(0, $exitCode, $content);
        self::assertStringContainsString('"status":"healthy"', $content);
    }

    public function test_health_check_returns_failure_when_a_required_check_fails(): void
    {
        config()->set('app.key', '');

        $output = new BufferedOutput;
        $exitCode = Artisan::call('health:check', ['--json' => true, '--redact' => true], $output);
        $content = $output->fetch();

        self::assertSame(1, $exitCode, $content);
        self::assertStringContainsString('"status":"unhealthy"', $content);
    }

    public function test_structural_queue_drift_is_reported_without_exposing_configuration_values(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.after_commit', false);
        config()->set('queue.connections.redis.retry_after', 300);
        config()->set('database.redis.default.password', 'runtime-health-test-secret');

        $checks = $this->app->make(RuntimeHealthProbe::class)->checks();

        self::assertFalse($checks['queue_after_commit']['passed']);
        self::assertFalse($checks['queue_retry_after']['passed']);

        $encoded = json_encode($checks, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('runtime-health-test-secret', $encoded);
    }

    public function test_redis_authentication_is_required_when_redis_is_a_runtime_dependency(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('database.redis.default.password', null);
        config()->set('database.redis.default.url', null);

        $checks = $this->app->make(RuntimeHealthProbe::class)->checks();

        self::assertFalse($checks['redis_authentication']['passed']);
    }

    public function test_mariadb_runtime_and_session_invariants_pass_on_the_supported_integration_target(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB runtime invariant verification requires the repository integration database.');
        }

        $checks = $this->app->make(RuntimeHealthProbe::class)->checks();

        self::assertTrue($checks['database_runtime']['passed'], json_encode($checks['database_runtime'], JSON_THROW_ON_ERROR));
        self::assertTrue($checks['database_session']['passed'], json_encode($checks['database_session'], JSON_THROW_ON_ERROR));
    }

    public function test_liveness_and_readiness_endpoints_are_safe_and_healthy(): void
    {
        $release = (string) config('app.version');

        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'alive', 'release' => $release]);

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'healthy', 'release' => $release]);
    }
}
