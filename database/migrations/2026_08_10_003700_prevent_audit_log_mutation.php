<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement DAT-004 OPS-001 SEC-003 QUA-004 QUA-011 */
    public function up(): void
    {
        $this->dropGuards();
        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
    }

    private function createGuards(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER audit_logs_update_guard',
            'BEFORE UPDATE ON audit_logs',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are append-only.';",
            'END',
        ]));

        DB::unprepared(implode("\n", [
            'CREATE TRIGGER audit_logs_delete_guard',
            'BEFORE DELETE ON audit_logs',
            'FOR EACH ROW',
            'BEGIN',
            "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are non-deletable.';",
            'END',
        ]));
    }

    private function dropGuards(): void
    {
        foreach ([
            'DROP TRIGGER IF EXISTS audit_logs_delete_guard',
            'DROP TRIGGER IF EXISTS audit_logs_update_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }
    }
};
