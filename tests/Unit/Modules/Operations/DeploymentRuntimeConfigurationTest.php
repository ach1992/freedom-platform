<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use Tests\TestCase;

/** @requirement RUN-001 RUN-002 RUN-003 RUN-004 OPS-003 QUA-011 */
final class DeploymentRuntimeConfigurationTest extends TestCase
{
    public function test_backup_environment_path_resolves_the_canonical_shared_release_link(): void
    {
        $originalBasePath = base_path();
        $fixture = storage_path('framework/testing/operations-config-canonical-'.bin2hex(random_bytes(4)));
        $release = $fixture.'/releases/test-release';
        $shared = $fixture.'/shared';
        mkdir($release, 0700, true);
        mkdir($shared, 0700, true);
        file_put_contents($shared.'/.env', "APP_ENV=testing\n");
        symlink('../../shared/.env', $release.'/.env');

        try {
            $this->app->setBasePath($release);
            /** @var array<string, mixed> $operations */
            $operations = require $originalBasePath.'/config/operations.php';

            self::assertSame(
                realpath($shared.'/.env'),
                $operations['backup']['config_files']['environment'],
            );
        } finally {
            $this->app->setBasePath($originalBasePath);
            $this->removeTree($fixture);
        }
    }

    public function test_backup_environment_path_does_not_resolve_an_unapproved_symlink(): void
    {
        $originalBasePath = base_path();
        $fixture = storage_path('framework/testing/operations-config-unapproved-'.bin2hex(random_bytes(4)));
        $release = $fixture.'/releases/test-release';
        $unapproved = $fixture.'/unapproved';
        mkdir($release, 0700, true);
        mkdir($unapproved, 0700, true);
        file_put_contents($unapproved.'/.env', "APP_ENV=testing\n");
        symlink('../../unapproved/.env', $release.'/.env');

        try {
            $this->app->setBasePath($release);
            /** @var array<string, mixed> $operations */
            $operations = require $originalBasePath.'/config/operations.php';

            self::assertSame(
                $release.'/.env',
                $operations['backup']['config_files']['environment'],
            );
        } finally {
            $this->app->setBasePath($originalBasePath);
            $this->removeTree($fixture);
        }
    }

    public function test_backup_environment_path_rejects_a_symlinked_shared_environment_target(): void
    {
        $originalBasePath = base_path();
        $fixture = storage_path('framework/testing/operations-config-shared-link-'.bin2hex(random_bytes(4)));
        $release = $fixture.'/releases/test-release';
        $shared = $fixture.'/shared';
        $outside = $fixture.'/outside';
        mkdir($release, 0700, true);
        mkdir($shared, 0700, true);
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/.env', "APP_ENV=testing\n");
        symlink('../outside/.env', $shared.'/.env');
        symlink('../../shared/.env', $release.'/.env');

        try {
            $this->app->setBasePath($release);
            /** @var array<string, mixed> $operations */
            $operations = require $originalBasePath.'/config/operations.php';

            self::assertSame(
                $release.'/.env',
                $operations['backup']['config_files']['environment'],
            );
        } finally {
            $this->app->setBasePath($originalBasePath);
            $this->removeTree($fixture);
        }
    }

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

    public function test_redis_blocking_timeout_returns_idle_workers_to_the_heartbeat_loop(): void
    {
        $blockFor = config('queue.connections.redis.block_for');
        $heartbeatInterval = config('operations.worker_heartbeat.interval_seconds');

        $this->assertSame(5, $blockFor);
        $this->assertIsInt($heartbeatInterval);
        $this->assertGreaterThan($blockFor, $heartbeatInterval);
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

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$name);
        }

        @rmdir($path);
    }
}
