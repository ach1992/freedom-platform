<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

return new class extends Migration
{
    /** @requirement PRV-002 PRV-003 DAT-003 SEC-002 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_running_capacity_release_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER provisioning_running_capacity_release_guard
BEFORE UPDATE ON panel_capacity_reservations
FOR EACH ROW
BEGIN
    IF OLD.state <> NEW.state
       AND NEW.state IN ('released', 'expired')
       AND EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           WHERE operation_row.capacity_reservation_id = OLD.id
             AND operation_row.operation_type = 'initial_provision'
             AND operation_row.state = 'running'
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Running initial provisioning holds capacity until the remote effect is durably finalized.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('provisioning_operations')
            ->where('operation_type', 'initial_provision')
            ->where('state', 'running')
            ->whereNotNull('capacity_reservation_id')
            ->exists()) {
            throw new RuntimeException('Cannot remove the running provisioning capacity fence while a remote effect is in progress.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_running_capacity_release_guard');
    }
};
