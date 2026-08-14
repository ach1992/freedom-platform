<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement USDT-002 IPG-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('usdt_manual_rate_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('request_key', 128)->unique();
            $table->decimal('rate_irr', 28, 8);
            $table->foreignId('created_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['created_at', 'id'], 'usdt_manual_rate_current_idx');
        });

        DB::statement('ALTER TABLE usdt_manual_rate_versions ADD CONSTRAINT usdt_manual_rate_positive_chk CHECK (`rate_irr` > 0)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_manual_rate_versions_insert_guard
BEFORE INSERT ON usdt_manual_rate_versions
FOR EACH ROW
BEGIN
    DECLARE active_admin_count INT DEFAULT 0;

    SELECT COUNT(*) INTO active_admin_count
    FROM administrators administrator_row
    WHERE administrator_row.id = NEW.created_by_administrator_id
      AND administrator_row.status = 'active';

    IF active_admin_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual rate setting requires one active administrator.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_manual_rate_versions_update_guard
BEFORE UPDATE ON usdt_manual_rate_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual rate setting history is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_manual_rate_versions_delete_guard
BEFORE DELETE ON usdt_manual_rate_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual rate setting history is non-deletable.';
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('usdt_manual_rate_versions')->exists()) {
            throw new RuntimeException('Cannot roll back USDT manual rate settings after managed financial configuration exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS usdt_manual_rate_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_manual_rate_versions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_manual_rate_versions_insert_guard');
        Schema::dropIfExists('usdt_manual_rate_versions');
    }
};