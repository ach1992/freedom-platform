<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
