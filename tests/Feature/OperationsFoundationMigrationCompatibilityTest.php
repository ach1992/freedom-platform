<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement OPS-001 OPS-003 QUA-011 */
final class OperationsFoundationMigrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_event_instants_use_explicit_microsecond_datetime_columns(): void
    {
        $expectedColumns = [
            'scheduled_task_runs' => ['started_at', 'finished_at'],
            'worker_heartbeats' => ['last_seen_at'],
            'alerts' => ['first_seen_at', 'last_seen_at', 'acknowledged_at', 'resolved_at'],
        ];

        $database = DB::connection()->getDatabaseName();

        foreach ($expectedColumns as $table => $columns) {
            foreach ($columns as $column) {
                $metadata = DB::selectOne(
                    <<<'SQL'
                    SELECT DATA_TYPE AS data_type,
                           DATETIME_PRECISION AS datetime_precision,
                           COLUMN_DEFAULT AS column_default
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = ?
                      AND TABLE_NAME = ?
                      AND COLUMN_NAME = ?
                    SQL,
                    [$database, $table, $column],
                );

                $this->assertNotNull($metadata, $table.'.'.$column.' must exist.');
                $this->assertSame('datetime', $metadata->data_type, $table.'.'.$column.' must avoid implicit TIMESTAMP defaults.');
                $this->assertSame(6, (int) $metadata->datetime_precision, $table.'.'.$column.' must retain microsecond precision.');
                $this->assertNull($metadata->column_default, $table.'.'.$column.' must be supplied explicitly by the application.');
            }
        }
    }

    public function test_operations_foundation_accepts_explicit_utc_microsecond_instants(): void
    {
        $instant = '2026-08-04 15:21:00.123456';

        DB::table('worker_heartbeats')->insert([
            'worker_id' => 'compatibility-worker',
            'queue' => 'default',
            'host_hash' => hash('sha256', 'compatibility-host'),
            'release_version' => 'test',
            'last_seen_at' => $instant,
            'created_at' => $instant,
            'updated_at' => $instant,
        ]);

        DB::table('alerts')->insert([
            'id' => '018f4d68-91b2-7c30-8a11-123456789abc',
            'severity' => 'warning',
            'event_name' => 'operations.compatibility_test',
            'deduplication_key' => hash('sha256', 'compatibility-alert'),
            'correlation_id' => 'compatibility-correlation',
            'occurrence_count' => 1,
            'first_seen_at' => $instant,
            'last_seen_at' => $instant,
            'created_at' => $instant,
            'updated_at' => $instant,
        ]);

        $heartbeat = DB::table('worker_heartbeats')
            ->where('worker_id', 'compatibility-worker')
            ->value('last_seen_at');
        $alert = DB::table('alerts')
            ->where('event_name', 'operations.compatibility_test')
            ->value('last_seen_at');

        $this->assertSame($instant, $heartbeat);
        $this->assertSame($instant, $alert);
    }
}
