<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * @requirement OPS-001 OPS-003
 */
final class WorkerHeartbeatCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_heartbeat_command_records_process_state(): void
    {
        $exitCode = Artisan::call('operations:worker-heartbeat', [
            'worker-id' => 'payments-01',
            '--queue' => 'critical-payments',
            '--release' => '0.2.0-test',
        ]);

        self::assertSame(0, $exitCode);
        $this->assertDatabaseHas('worker_heartbeats', [
            'worker_id' => 'payments-01',
            'queue' => 'critical-payments',
            'release_version' => '0.2.0-test',
        ]);
    }

    public function test_invalid_heartbeat_input_is_rejected_without_writing(): void
    {
        $exitCode = Artisan::call('operations:worker-heartbeat', [
            'worker-id' => '',
            '--queue' => 'default',
        ]);

        self::assertSame(2, $exitCode);
        self::assertSame(0, DB::table('worker_heartbeats')->count());
    }

    public function test_stale_heartbeat_creates_one_deduplicated_critical_alert(): void
    {
        Artisan::call('operations:worker-heartbeat', [
            'worker-id' => 'provisioning-01',
            '--queue' => 'provisioning',
        ]);

        DB::table('worker_heartbeats')
            ->where('worker_id', 'provisioning-01')
            ->update(['last_seen_at' => now('UTC')->subMinutes(10)]);

        $firstOutput = new BufferedOutput;
        $firstExitCode = Artisan::call('operations:check-worker-heartbeats', [
            '--max-age' => 120,
            '--json' => true,
        ], $firstOutput);

        self::assertSame(1, $firstExitCode, $firstOutput->fetch());
        $this->assertDatabaseHas('alerts', [
            'severity' => 'critical',
            'event_name' => 'operations.worker_heartbeat_stale',
            'occurrence_count' => 1,
        ]);

        $secondExitCode = Artisan::call('operations:check-worker-heartbeats', [
            '--max-age' => 120,
            '--json' => true,
        ]);

        self::assertSame(1, $secondExitCode);
        self::assertSame(1, DB::table('alerts')->count());
        self::assertSame(2, DB::table('alerts')->value('occurrence_count'));
    }

    public function test_fresh_heartbeats_do_not_create_alerts(): void
    {
        Artisan::call('operations:worker-heartbeat', [
            'worker-id' => 'telegram-01',
            '--queue' => 'telegram-delivery',
        ]);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('operations:check-worker-heartbeats', [
            '--max-age' => 120,
            '--json' => true,
        ], $output);

        self::assertSame(0, $exitCode, $output->fetch());
        self::assertSame(0, DB::table('alerts')->count());
    }
}
