<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReliabilityFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_tables_exist(): void
    {
        foreach ([
            'idempotency_keys',
            'processed_telegram_updates',
            'outbox_messages',
            'audit_logs',
            'scheduled_task_runs',
            'worker_heartbeats',
            'alerts',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing foundation table: {$table}");
        }
    }
}
