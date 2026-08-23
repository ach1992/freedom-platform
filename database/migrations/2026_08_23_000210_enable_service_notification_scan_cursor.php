<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-013 SVC-014 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('service_notification_states')
            || ! Schema::hasTable('service_operational_authority_capability')) {
            throw new RuntimeException('Service notification scan cursor requires the accepted notification authority foundation.');
        }

        $this->recoverInterruptedInstall();

        if (! Schema::hasTable('service_notification_scan_cursor')) {
            Schema::create('service_notification_scan_cursor', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('last_service_subscription_id')->nullable();
                $table->dateTime('updated_at', 6);
            });

            DB::table('service_notification_scan_cursor')->insert([
                'id' => 1,
                'last_service_subscription_id' => null,
                'updated_at' => now('UTC'),
            ]);
        }

        $this->installGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::dropIfExists('service_notification_scan_cursor');
    }

    private function recoverInterruptedInstall(): void
    {
        if (! Schema::hasTable('service_notification_scan_cursor')) {
            return;
        }

        $rows = DB::table('service_notification_scan_cursor')
            ->orderBy('id')
            ->get(['id', 'last_service_subscription_id']);
        if ($rows->isEmpty()
            || ($rows->count() === 1
                && (int) $rows[0]->id === 1
                && $rows[0]->last_service_subscription_id === null)) {
            $this->dropGuards();
            Schema::drop('service_notification_scan_cursor');

            return;
        }

        throw new RuntimeException('Service notification scan cursor migration cannot repair a non-pristine interrupted install.');
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_cursor_insert_guard
BEFORE INSERT ON service_notification_scan_cursor
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification scan cursor is a singleton.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_cursor_update_guard
BEFORE UPDATE ON service_notification_scan_cursor
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') <> 'service_notification_cursor_v1'
       OR OLD.id <> 1 OR NEW.id <> 1
       OR NOT (OLD.last_service_subscription_id <=> @app_service_notification_cursor_previous_id)
       OR NEW.last_service_subscription_id <> COALESCE(@app_service_notification_cursor_next_id, 0)
       OR NEW.last_service_subscription_id IS NULL
       OR NEW.updated_at <> @app_service_notification_timestamp THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification scan cursor authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_cursor_delete_guard
BEFORE DELETE ON service_notification_scan_cursor
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification scan cursor cannot be deleted.';
END
SQL);
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_cursor_delete_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_cursor_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_cursor_insert_guard`');
    }
};
