<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement LOC-001 CNT-001 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        Schema::create('localization_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('translation_key', 191);
            $table->string('locale', 8);
            $table->text('override_value')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('updated_by_administrator_id');
            $table->timestamps(6);

            $table->unique(['translation_key', 'locale'], 'localization_override_key_locale_unique');
            $table->index(['locale', 'translation_key'], 'localization_override_locale_key_idx');
            $table->foreign('updated_by_administrator_id', 'localization_override_admin_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
        });

        Schema::create('localization_override_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('localization_override_id');
            $table->unsignedBigInteger('version');
            $table->string('action', 16);
            $table->text('override_value')->nullable();
            $table->unsignedBigInteger('actor_administrator_id');
            $table->string('request_fingerprint', 128);
            $table->timestamp('created_at', 6)->useCurrent();

            $table->unique(['localization_override_id', 'version'], 'localization_override_version_unique');
            $table->unique(['action', 'request_fingerprint'], 'localization_override_action_fingerprint_unique');
            $table->index(['localization_override_id', 'version'], 'localization_override_history_idx');
            $table->foreign('localization_override_id', 'localization_override_version_parent_fk')
                ->references('id')->on('localization_overrides')->restrictOnDelete();
            $table->foreign('actor_administrator_id', 'localization_override_version_admin_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE localization_overrides ADD CONSTRAINT localization_override_key_chk CHECK (`translation_key` REGEXP '^[A-Za-z0-9_][A-Za-z0-9_.-]{0,190}$')");
        DB::statement("ALTER TABLE localization_overrides ADD CONSTRAINT localization_override_locale_chk CHECK (`locale` IN ('fa','en'))");
        DB::statement('ALTER TABLE localization_overrides ADD CONSTRAINT localization_override_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE localization_overrides ADD CONSTRAINT localization_override_value_chk CHECK (`override_value` IS NULL OR CHAR_LENGTH(`override_value`) BETWEEN 1 AND 4096)');

        DB::statement('ALTER TABLE localization_override_versions ADD CONSTRAINT localization_override_history_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE localization_override_versions ADD CONSTRAINT localization_override_history_action_chk CHECK (`action` IN ('set','reset','restore'))");
        DB::statement('ALTER TABLE localization_override_versions ADD CONSTRAINT localization_override_history_value_chk CHECK (`override_value` IS NULL OR CHAR_LENGTH(`override_value`) BETWEEN 1 AND 4096)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER localization_overrides_update_guard
BEFORE UPDATE ON localization_overrides
FOR EACH ROW
BEGIN
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Localization override version must advance exactly once.';
    END IF;
    IF NOT (NEW.translation_key <=> OLD.translation_key)
        OR NOT (NEW.locale <=> OLD.locale)
        OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Localization override identity is immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER localization_overrides_delete_guard
BEFORE DELETE ON localization_overrides
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Localization override rows are reset, not deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER localization_override_versions_update_guard
BEFORE UPDATE ON localization_override_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Localization override history is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER localization_override_versions_delete_guard
BEFORE DELETE ON localization_override_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Localization override history is immutable.';
END
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('localization_override_versions');
        Schema::dropIfExists('localization_overrides');
    }
};
