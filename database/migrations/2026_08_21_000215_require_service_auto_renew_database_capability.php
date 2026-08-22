<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_operational_authority_capability')) {
            throw new RuntimeException('Service auto-renew database capability requires the operational capability foundation.');
        }

        foreach ([
            ['sarp_cap_insert_guard', 'plan_offering_auto_renew_policies', 'INSERT'],
            ['sarp_cap_update_guard', 'plan_offering_auto_renew_policies', 'UPDATE'],
            ['sarph_cap_insert_guard', 'plan_offering_auto_renew_policy_histories', 'INSERT'],
            ['sarc_cap_insert_guard', 'service_auto_renew_configurations', 'INSERT'],
            ['sarc_cap_update_guard', 'service_auto_renew_configurations', 'UPDATE'],
            ['sarch_cap_insert_guard', 'service_auto_renew_configuration_histories', 'INSERT'],
        ] as [$trigger, $table, $event]) {
            $this->createCapabilityGuard($trigger, $table, $event);
        }
    }

    public function down(): void
    {
        foreach (['plan_offering_auto_renew_policies', 'service_auto_renew_configurations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove Service auto-renew capability guards while auto-renew authority rows exist.');
            }
        }

        DB::unprepared('DROP TRIGGER IF EXISTS `sarp_cap_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sarp_cap_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sarph_cap_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sarc_cap_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sarc_cap_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sarch_cap_insert_guard`');
    }

    private function createCapabilityGuard(string $trigger, string $table, string $event): void
    {
        if (! in_array($event, ['INSERT', 'UPDATE'], true)) {
            throw new RuntimeException('Service auto-renew capability guard event is invalid.');
        }

        DB::unprepared(<<<SQL
CREATE TRIGGER IF NOT EXISTS `{$trigger}`
BEFORE {$event} ON `{$table}`
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew database capability is invalid.';
    END IF;
END
SQL);
    }
};
