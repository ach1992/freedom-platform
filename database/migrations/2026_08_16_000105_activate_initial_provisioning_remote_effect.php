<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BARRIER = 'initial_provisioning_remote_effect_bootstrap_barrier';

    /** @requirement PAY-003 PRV-001 PRV-002 PRV-003 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_remote_effect_events')
            || ! Schema::hasColumn('provisioning_operations', 'effect_fence_key')
            || ! Schema::hasColumn('provisioning_operations', 'route_selection_id')
            || ! Schema::hasColumn('provisioning_operations', 'capacity_reservation_id')
            || ! Schema::hasColumn('provisioning_operations', 'remote_username')
            || ! Schema::hasColumn('provisioning_operations', 'remote_effect_started_at')
            || ! Schema::hasColumn('service_subscriptions', 'remote_service_id')
        ) {
            throw new RuntimeException('Initial provisioning remote-effect schema is incomplete; activation remains fail-closed.');
        }

        if (! $this->triggerExists(self::BARRIER)
            || ! $this->triggerContains('provisioning_operations_update_guard', 'recovery_transition')
            || ! $this->triggerContains('provisioning_operations_update_guard', 'initial_remote_effect_v1')
            || ! $this->triggerContains('provisioning_remote_effect_events_insert_guard', 'reconciliation_scheduled')
            || ! $this->triggerContains('purchase_refunds_provisioning_invalidation', 'Initial provisioning remote-effect fence is active')
            || ! $this->triggerContains('provisioning_running_capacity_release_guard', 'Running initial provisioning holds capacity')
            || ! $this->triggerContains('panel_targets_update_guard', 'current active connection version')
        ) {
            throw new RuntimeException('Initial provisioning remote-effect safety guards are incomplete; activation remains fail-closed.');
        }

        // This is intentionally the final enabling DDL statement. Until it succeeds, queued work
        // can exist but no provisioning operation can enter the remote-effect state machine.
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::BARRIER);
    }

    public function down(): void
    {
        // Rollback becomes fail-closed before any earlier remote-effect migration can weaken a guard.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER initial_provisioning_remote_effect_bootstrap_barrier
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning remote-effect migration is incomplete.';
END
SQL);
    }

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
