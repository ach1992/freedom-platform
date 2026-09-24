<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CNT-002 CNT-003 ACL-001 ACL-002 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        Schema::create('telegram_menu_configuration_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('menu_key', 64);
            $table->unsignedBigInteger('version');
            $table->json('definition_json');
            $table->char('definition_hash', 64);
            $table->unsignedBigInteger('created_by_administrator_id');
            $table->timestamp('created_at', 6)->useCurrent();

            $table->unique(['menu_key', 'version'], 'telegram_menu_version_unique');
            $table->index(['menu_key', 'version'], 'telegram_menu_version_history_idx');
            $table->foreign('created_by_administrator_id', 'telegram_menu_version_admin_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
        });

        Schema::create('telegram_menu_configuration_heads', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('menu_key', 64)->unique();
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->unsignedBigInteger('next_version')->default(1);
            $table->unsignedBigInteger('updated_by_administrator_id')->nullable();
            $table->timestamps(6);

            $table->foreign('active_version_id', 'telegram_menu_head_active_version_fk')
                ->references('id')->on('telegram_menu_configuration_versions')->restrictOnDelete();
            $table->foreign('updated_by_administrator_id', 'telegram_menu_head_admin_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE telegram_menu_configuration_versions ADD CONSTRAINT telegram_menu_version_key_chk CHECK (`menu_key` = 'home' OR `menu_key` REGEXP '^submenu\\.[a-z0-9][a-z0-9_.-]{0,54}$')");
        DB::statement('ALTER TABLE telegram_menu_configuration_versions ADD CONSTRAINT telegram_menu_version_number_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE telegram_menu_configuration_versions ADD CONSTRAINT telegram_menu_version_hash_chk CHECK (`definition_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE telegram_menu_configuration_versions ADD CONSTRAINT telegram_menu_version_json_chk CHECK (JSON_VALID(`definition_json`) = 1)');
        DB::statement("ALTER TABLE telegram_menu_configuration_heads ADD CONSTRAINT telegram_menu_head_key_chk CHECK (`menu_key` = 'home' OR `menu_key` REGEXP '^submenu\\.[a-z0-9][a-z0-9_.-]{0,54}$')");
        DB::statement('ALTER TABLE telegram_menu_configuration_heads ADD CONSTRAINT telegram_menu_head_generation_chk CHECK (`generation` >= 0)');
        DB::statement('ALTER TABLE telegram_menu_configuration_heads ADD CONSTRAINT telegram_menu_head_next_version_chk CHECK (`next_version` >= 1)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_menu_versions_update_guard
BEFORE UPDATE ON telegram_menu_configuration_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram menu configuration versions are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_menu_versions_delete_guard
BEFORE DELETE ON telegram_menu_configuration_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram menu configuration versions are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_menu_heads_update_guard
BEFORE UPDATE ON telegram_menu_configuration_heads
FOR EACH ROW
BEGIN
    DECLARE active_menu_key VARCHAR(64) DEFAULT NULL;

    IF NOT (NEW.menu_key <=> OLD.menu_key)
        OR NOT (NEW.created_at <=> OLD.created_at)
        OR NOT (NEW.id <=> OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram menu configuration head identity is immutable.';
    END IF;

    IF NEW.active_version_id IS NOT NULL THEN
        SELECT menu_key INTO active_menu_key
        FROM telegram_menu_configuration_versions
        WHERE id = NEW.active_version_id
        LIMIT 1;

        IF active_menu_key IS NULL OR BINARY active_menu_key <> BINARY NEW.menu_key THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram menu active version must belong to the same menu.';
        END IF;
    END IF;

    IF NOT (
        (
            NEW.next_version = OLD.next_version + 1
            AND NEW.generation = OLD.generation
            AND (NEW.active_version_id <=> OLD.active_version_id)
            AND (NEW.updated_by_administrator_id <=> OLD.updated_by_administrator_id)
        )
        OR
        (
            NEW.next_version = OLD.next_version
            AND NEW.generation = OLD.generation + 1
            AND NEW.updated_by_administrator_id IS NOT NULL
        )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram menu configuration head transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_menu_heads_delete_guard
BEFORE DELETE ON telegram_menu_configuration_heads
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram menu configuration heads cannot be deleted.';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_menu_heads_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_menu_heads_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_menu_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_menu_versions_update_guard');

        Schema::dropIfExists('telegram_menu_configuration_heads');
        Schema::dropIfExists('telegram_menu_configuration_versions');
    }
};
