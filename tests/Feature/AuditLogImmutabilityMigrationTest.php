<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement DAT-004 OPS-001 SEC-003 QUA-004 QUA-011 */
final class AuditLogImmutabilityMigrationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_migrate_fresh_installs_deterministic_audit_log_guards(): void
    {
        self::assertSame([
            'audit_logs_delete_guard',
            'audit_logs_update_guard',
        ], $this->auditLogTriggerNames());
    }

    public function test_reapplying_the_forward_migration_preserves_both_guards(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_10_003700_prevent_audit_log_mutation.php');

        $migration->up();

        self::assertSame([
            'audit_logs_delete_guard',
            'audit_logs_update_guard',
        ], $this->auditLogTriggerNames());
    }

    /** @return list<string> */
    private function auditLogTriggerNames(): array
    {
        $database = DB::connection()->getDatabaseName();

        return array_map(
            static fn (object $trigger): string => $trigger->trigger_name,
            DB::select(
                <<<'SQL'
                SELECT TRIGGER_NAME AS trigger_name
                FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = ?
                  AND EVENT_OBJECT_TABLE = 'audit_logs'
                ORDER BY TRIGGER_NAME
                SQL,
                [$database],
            ),
        );
    }
}
