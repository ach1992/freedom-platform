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
            throw new RuntimeException('Service auto-renew runtime capability requires the operational capability foundation.');
        }

        foreach ([
            ['sara_cap_insert_guard', 'service_auto_renew_attempts', 'INSERT'],
            ['sara_cap_update_guard', 'service_auto_renew_attempts', 'UPDATE'],
            ['sarae_cap_insert_guard', 'service_auto_renew_attempt_events', 'INSERT'],
            ['sarni_cap_insert_guard', 'service_auto_renew_notification_intents', 'INSERT'],
        ] as [$trigger, $table, $event]) {
            $this->createCapabilityGuard($trigger, $table, $event);
        }
    }

    public function down(): void
    {
        foreach ([
            'plan_offering_auto_renew_policies',
            'plan_offering_auto_renew_policy_histories',
            'service_auto_renew_configurations',
            'service_auto_renew_configuration_histories',
            'service_auto_renew_attempts',
            'service_auto_renew_attempt_events',
            'service_auto_renew_notification_intents',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove Service auto-renew runtime capability guards while auto-renew authority rows exist.');
            }
        }

        DB::unprepared('DROP TRIGGER IF EXISTS `sara_cap_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sara_cap_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sarae_cap_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `sarni_cap_insert_guard`');
    }

    private function createCapabilityGuard(string $trigger, string $table, string $event): void
    {
        if (! in_array($event, ['INSERT', 'UPDATE'], true)) {
            throw new RuntimeException('Service auto-renew runtime capability guard event is invalid.');
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
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew runtime database capability is invalid.';
    END IF;
END
SQL);
    }
};
