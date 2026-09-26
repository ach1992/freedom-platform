<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const VERSION_ONE_OPERATION_CODES = [
        'renew', 'add_data', 'add_days', 'add_data_days', 'reset_usage',
        'suspend', 'activate', 'rotate_subscription_link', 'refresh_details', 'delete',
        'clear_ip_sessions', 'change_protocol', 'change_location', 'change_plan',
    ];

    /** @var list<string> */
    private const PREVIOUS_OPERATION_CODES = [
        'renew', 'add_data', 'add_days', 'add_data_days', 'reset_usage', 'change_plan',
    ];

    /** @requirement SVC-004 SVC-005 SVC-006 DAT-002 DAT-003 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('plan_offering_operations')) {
            return;
        }

        $this->replaceConstraint(self::VERSION_ONE_OPERATION_CODES);
    }

    public function down(): void
    {
        if (! Schema::hasTable('plan_offering_operations')) {
            return;
        }

        $newCodes = array_values(array_diff(self::VERSION_ONE_OPERATION_CODES, self::PREVIOUS_OPERATION_CODES));
        if (DB::table('plan_offering_operations')->whereIn('operation_code', $newCodes)->exists()) {
            throw new RuntimeException('Cannot narrow Service operation policy codes while Version 1 lifecycle policies exist.');
        }

        $this->replaceConstraint(self::PREVIOUS_OPERATION_CODES);
    }

    /** @param list<string> $codes */
    private function replaceConstraint(array $codes): void
    {
        DB::statement('ALTER TABLE plan_offering_operations DROP CONSTRAINT plan_offering_operations_code_chk');

        $quoted = implode(',', array_map(
            static fn (string $code): string => "'".str_replace("'", "''", $code)."'",
            $codes,
        ));
        DB::statement("ALTER TABLE plan_offering_operations ADD CONSTRAINT plan_offering_operations_code_chk CHECK (`operation_code` IN ({$quoted}))");
    }
};
