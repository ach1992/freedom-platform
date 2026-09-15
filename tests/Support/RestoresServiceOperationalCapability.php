<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Provisioning\Application\ServiceOperationalDatabaseCapability;
use Illuminate\Support\Facades\DB;

trait RestoresServiceOperationalCapability
{
    private function restoreServiceOperationalCapabilitySingleton(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('service_operational_authority_capability')
            || DB::table('service_operational_authority_capability')->exists()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_insert_guard');
        DB::table('service_operational_authority_capability')->insert([
            'id' => 1,
            'capability_hash' => (new ServiceOperationalDatabaseCapability)->expectedHash(),
            'created_at' => now('UTC'),
        ]);
        DB::unprepared("CREATE OR REPLACE TRIGGER service_operational_capability_insert_guard BEFORE INSERT ON service_operational_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational database capability is immutable.'; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER service_operational_capability_update_guard BEFORE UPDATE ON service_operational_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational database capability is immutable.'; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER service_operational_capability_delete_guard BEFORE DELETE ON service_operational_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational database capability is immutable.'; END");
    }
}
