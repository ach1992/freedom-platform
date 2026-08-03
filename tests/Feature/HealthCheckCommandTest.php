<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class HealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_health_check_passes_with_required_services(): void
    {
        $exitCode = Artisan::call('health:check', ['--critical' => true, '--json' => true]);

        self::assertSame(0, $exitCode, Artisan::output());
        self::assertStringContainsString('"status":"healthy"', Artisan::output());
    }

    public function test_health_check_returns_failure_when_a_required_check_fails(): void
    {
        config()->set('app.key', '');

        $exitCode = Artisan::call('health:check', ['--json' => true, '--redact' => true]);

        self::assertSame(1, $exitCode, Artisan::output());
        self::assertStringContainsString('"status":"unhealthy"', Artisan::output());
    }

    public function test_liveness_and_readiness_endpoints_are_safe_and_healthy(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'alive', 'release' => '0.0.0-dev']);

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'healthy', 'release' => '0.0.0-dev']);
    }
}
