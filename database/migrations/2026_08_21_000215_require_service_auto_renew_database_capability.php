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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS `sarp_cap_insert_guard`
BEFORE INSERT ON `plan_offering_auto_renew_policies`
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew database capability is invalid.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS `sarp_cap_update_guard`
BEFORE UPDATE ON `plan_offering_auto_renew_policies`
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew database capability is invalid.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS `sarph_cap_insert_guard`
BEFORE INSERT ON `plan_offering_auto_renew_policy_histories`
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew database capability is invalid.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS `sarc_cap_insert_guard`
BEFORE INSERT ON `service_auto_renew_configurations`
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew database capability is invalid.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS `sarc_cap_update_guard`
BEFORE UPDATE ON `service_auto_renew_configurations`
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew database capability is invalid.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS `sarch_cap_insert_guard`
BEFORE INSERT ON `service_auto_renew_configuration_histories`
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew database capability is invalid.';
    END IF;
END
SQL);
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
};
