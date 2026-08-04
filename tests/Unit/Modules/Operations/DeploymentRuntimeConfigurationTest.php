<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use Tests\TestCase;

/** @requirement RUN-001 RUN-002 RUN-003 RUN-004 OPS-003 QUA-011 */
final class DeploymentRuntimeConfigurationTest extends TestCase
{
    public function test_supervisor_template_assigns_unique_heartbeat_identity_to_every_worker_group(): void
    {
        $configuration = (string) file_get_contents(base_path('deploy/supervisor/freedom-platform.conf'));

        $this->assertSame(3, substr_count($configuration, 'WORKER_HEARTBEAT_ENABLED="true"'));
        $this->assertSame(3, substr_count($configuration, 'WORKER_NAME="%(program_name)s_%(process_num)02d"'));
        $this->assertSame(3, substr_count($configuration, 'WORKER_HEARTBEAT_INTERVAL_SECONDS="30"'));
        $this->assertSame(3, substr_count($configuration, 'WORKER_HEARTBEAT_STALE_AFTER_SECONDS="480"'));
        $this->assertStringContainsString('WORKER_QUEUE_GROUP="critical-payments,bank-verification,gift-card-verification"', $configuration);
        $this->assertStringContainsString('WORKER_QUEUE_GROUP="provisioning,telegram-ingress,telegram-delivery,synchronization,default"', $configuration);
        $this->assertStringContainsString('WORKER_QUEUE_GROUP="broadcasts,reports,backups,maintenance"', $configuration);
    }

    public function test_stale_threshold_exceeds_the_longest_worker_timeout(): void
    {
        $configuration = (string) file_get_contents(base_path('deploy/supervisor/freedom-platform.conf'));
        preg_match_all('/--timeout=(\d+)/', $configuration, $matches);
        $timeouts = array_map('intval', $matches[1] ?? []);

        $this->assertNotSame([], $timeouts);
        $this->assertGreaterThan(max($timeouts), (int) config('operations.worker_heartbeat.stale_after_seconds'));
    }

    public function test_scheduler_uses_exactly_one_cron_entry(): void
    {
        $cron = trim((string) file_get_contents(base_path('deploy/cron/freedom-platform.cron')));
        $lines = array_values(array_filter(
            preg_split('/\R/', $cron) ?: [],
            static fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), '#'),
        ));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('artisan schedule:run', $lines[0]);
        $this->assertStringContainsString('/www/server/php/84/bin/php', $lines[0]);
    }
}
