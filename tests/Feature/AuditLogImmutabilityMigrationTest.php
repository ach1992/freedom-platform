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

    public function test_migrate_fresh_installs_preserve_global_audit_log_guards(): void
    {
        $this->assertGlobalAuditLogGuardsArePresent();
    }

    public function test_reapplying_the_forward_migration_preserves_global_guards(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_10_003700_prevent_audit_log_mutation.php');

        $migration->up();

        $this->assertGlobalAuditLogGuardsArePresent();
    }

    private function assertGlobalAuditLogGuardsArePresent(): void
    {
        $triggers = $this->auditLogTriggerNames();

        self::assertContains('audit_logs_delete_guard', $triggers);
        self::assertContains('audit_logs_update_guard', $triggers);
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
