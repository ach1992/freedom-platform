<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BARRIER = 'initial_provisioning_remote_effect_bootstrap_barrier';

    /** @requirement PAY-003 PRV-002 PRV-003 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('provisioning_operations')) {
            throw new RuntimeException('Initial provisioning remote-effect barrier requires provisioning operations.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER initial_provisioning_remote_effect_bootstrap_barrier
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning remote-effect migration is incomplete.';
END
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('provisioning_operations')) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::BARRIER);

            return;
        }

        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'provisioning_operations_update_guard'
  AND LOCATE('Provisioning Operation mutation is not enabled by current queue authority.', ACTION_STATEMENT) > 0
SQL);
        if ($row === null || (int) $row->aggregate !== 1) {
            throw new RuntimeException('Cannot remove the remote-effect migration barrier before the queue-era fail-closed update guard is restored.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::BARRIER);
    }
};
