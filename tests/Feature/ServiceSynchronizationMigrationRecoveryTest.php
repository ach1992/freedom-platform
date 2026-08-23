<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** @requirement SVC-010 SVC-013 DAT-003 DAT-004 QUA-004 */
final class ServiceSynchronizationMigrationRecoveryTest extends TestCase
{
    use DatabaseTruncation;

    public function test_empty_interrupted_sync_authority_surface_is_rebuilt_from_scratch(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_23_000100_enable_service_sync_authority.php');

        $migration->down();
        Schema::create('service_sync_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
        });

        self::assertTrue(Schema::hasTable('service_sync_runs'));
        self::assertFalse(Schema::hasTable('service_sync_snapshots'));

        $migration->up();

        foreach ([
            'service_sync_runs',
            'service_sync_leases',
            'service_sync_snapshots',
            'service_sync_anomalies',
            'service_sync_anomaly_events',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table));
        }
        self::assertSame(1, $this->triggerCount('service_sync_snapshots_insert_guard'));
        self::assertSame(1, $this->triggerCount('service_sync_events_insert_guard'));
    }

    public function test_interrupted_sync_authority_surface_with_durable_rows_fails_closed(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_23_000100_enable_service_sync_authority.php');

        $migration->down();
        Schema::create('service_sync_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
        });
        DB::table('service_sync_runs')->insert(['id' => 1]);

        try {
            $migration->up();
            self::fail('Interrupted Service synchronization authority with durable rows must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot repair an interrupted install after authority rows exist', $exception->getMessage());
            self::assertTrue(Schema::hasTable('service_sync_runs'));
            self::assertSame(1, DB::table('service_sync_runs')->count());
            self::assertFalse(Schema::hasTable('service_sync_snapshots'));
        } finally {
            Schema::dropIfExists('service_sync_runs');
            $migration->up();
        }
    }

    private function triggerCount(string $trigger): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row === null ? 0 : (int) $row->aggregate;
    }
}
