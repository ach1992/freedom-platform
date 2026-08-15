<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
    public function up(): void
    {
        $boundaries = [
            ['service_subscriptions', 'service_subscriptions_bootstrap_block_chk', 'service_subscriptions_insert_guard'],
            ['provisioning_operations', 'provisioning_operations_bootstrap_block_chk', 'provisioning_operations_insert_guard'],
            ['provisioning_operation_histories', 'provisioning_operation_histories_bootstrap_block_chk', 'provisioning_operation_histories_insert_guard'],
        ];

        foreach ($boundaries as [$table, $constraint, $trigger]) {
            if (! Schema::hasTable($table) || ! $this->constraintExists($table, $constraint)) {
                continue;
            }

            if (! $this->triggerExists($trigger)) {
                throw new RuntimeException('Provisioning bootstrap barrier cannot be released before its MariaDB trigger guard exists.');
            }

            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }
    }

    public function down(): void
    {
        foreach ([
            ['service_subscriptions', 'service_subscriptions_bootstrap_block_chk'],
            ['provisioning_operations', 'provisioning_operations_bootstrap_block_chk'],
            ['provisioning_operation_histories', 'provisioning_operation_histories_bootstrap_block_chk'],
        ] as [$table, $constraint]) {
            if (! Schema::hasTable($table) || $this->constraintExists($table, $constraint)) {
                continue;
            }

            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot restore provisioning bootstrap barrier while authority rows exist.');
            }

            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (0 = 1)");
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
