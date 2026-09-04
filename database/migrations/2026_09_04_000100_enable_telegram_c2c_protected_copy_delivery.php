<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement C2C-001 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-004 */
    public function up(): void
    {
        Schema::create('telegram_c2c_protected_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->char('request_key_hash', 64)->unique();
            $table->ulid('c2c_reservation_public_id')->unique();
            $table->foreignId('telegram_account_id')->constrained('telegram_accounts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('locale', 8);
            $table->uuid('outbox_event_id')->unique();
            $table->string('correlation_id', 64);
            $table->string('state', 32);
            $table->unsignedInteger('state_version')->default(1);
            $table->unsignedInteger('provider_attempts')->default(0);
            $table->dateTime('provider_boundary_started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['state', 'updated_at'], 'tg_c2c_protected_state_updated_idx');
        });

        DB::statement("ALTER TABLE telegram_c2c_protected_deliveries ADD CONSTRAINT tg_c2c_protected_state_chk CHECK (`state` IN ('prepared','sending','succeeded','failed_final','uncertain','review_required'))");
        DB::statement("ALTER TABLE telegram_c2c_protected_deliveries ADD CONSTRAINT tg_c2c_protected_locale_chk CHECK (`locale` IN ('fa','en'))");
        DB::statement('ALTER TABLE telegram_c2c_protected_deliveries ADD CONSTRAINT tg_c2c_protected_request_hash_chk CHECK (CHAR_LENGTH(`request_key_hash`) = 64)');
        DB::statement('ALTER TABLE telegram_c2c_protected_deliveries ADD CONSTRAINT tg_c2c_protected_version_chk CHECK (`state_version` >= 1 AND `provider_attempts` >= 0)');
        DB::statement('ALTER TABLE telegram_c2c_protected_deliveries ADD CONSTRAINT tg_c2c_protected_result_chk CHECK (`result_code` IS NULL OR `result_code` REGEXP \'^[a-z0-9_.:-]{1,64}$\')');
        DB::statement('ALTER TABLE telegram_c2c_protected_deliveries ADD CONSTRAINT tg_c2c_protected_retry_chk CHECK (`retry_after_seconds` IS NULL OR `retry_after_seconds` BETWEEN 1 AND 86400)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER tg_c2c_protected_deliveries_insert_guard
BEFORE INSERT ON telegram_c2c_protected_deliveries
FOR EACH ROW
BEGIN
    IF COALESCE(@app_tg_c2c_protected_insert_authority, '') <> 'telegram_c2c_protected_insert_v1'
       OR BINARY NEW.public_id <> BINARY COALESCE(@app_tg_c2c_protected_public_id, '')
       OR NEW.telegram_account_id <> COALESCE(@app_tg_c2c_protected_telegram_account_id, 0)
       OR NEW.user_id <> COALESCE(@app_tg_c2c_protected_user_id, 0)
       OR NEW.telegram_user_id <> COALESCE(@app_tg_c2c_protected_telegram_user_id, 0)
       OR BINARY NEW.c2c_reservation_public_id <> BINARY COALESCE(@app_tg_c2c_protected_reservation_public_id, '')
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_tg_c2c_protected_request_key_hash, '')
       OR BINARY NEW.outbox_event_id <> BINARY COALESCE(@app_tg_c2c_protected_outbox_event_id, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_tg_c2c_protected_correlation_id, '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery insert lacks authority.';
    END IF;
    IF NEW.state <> 'prepared'
       OR NEW.state_version <> 1
       OR NEW.provider_attempts <> 0
       OR NEW.provider_boundary_started_at IS NOT NULL
       OR NEW.completed_at IS NOT NULL
       OR NEW.telegram_message_id IS NOT NULL
       OR NEW.result_code IS NOT NULL
       OR NEW.retry_after_seconds IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery initial state is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER tg_c2c_protected_deliveries_update_guard
BEFORE UPDATE ON telegram_c2c_protected_deliveries
FOR EACH ROW
BEGIN
    IF COALESCE(@app_tg_c2c_protected_effect_authority, '') <> 'telegram_c2c_protected_effect_v1'
       OR BINARY OLD.public_id <> BINARY COALESCE(@app_tg_c2c_protected_public_id, '')
       OR OLD.state_version <> COALESCE(@app_tg_c2c_protected_expected_version, 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery update lacks authority.';
    END IF;
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.request_key_hash <=> OLD.request_key_hash)
       OR NOT (NEW.c2c_reservation_public_id <=> OLD.c2c_reservation_public_id)
       OR NOT (NEW.telegram_account_id <=> OLD.telegram_account_id)
       OR NOT (NEW.user_id <=> OLD.user_id)
       OR NOT (NEW.telegram_user_id <=> OLD.telegram_user_id)
       OR NOT (NEW.locale <=> OLD.locale)
       OR NOT (NEW.outbox_event_id <=> OLD.outbox_event_id)
       OR NOT (NEW.correlation_id <=> OLD.correlation_id)
       OR NOT (NEW.created_at <=> OLD.created_at)
       OR NEW.state_version <> OLD.state_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery immutable authority changed.';
    END IF;

    IF OLD.state = 'prepared' AND NEW.state = 'sending' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts + 1
           OR NEW.provider_boundary_started_at IS NULL
           OR NEW.completed_at IS NOT NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NOT NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery provider boundary is invalid.';
        END IF;
    ELSEIF OLD.state = 'prepared' AND NEW.state = 'failed_final' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NEW.provider_boundary_started_at IS NOT NULL
           OR NEW.completed_at IS NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery pre-boundary failure is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state IN ('succeeded','failed_final','uncertain','review_required') THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NEW.provider_boundary_started_at IS NULL
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NULL
           OR NEW.result_code IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery terminal result is invalid.';
        END IF;
        IF NEW.state = 'succeeded' AND (NEW.telegram_message_id IS NULL OR NEW.retry_after_seconds IS NOT NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery success evidence is invalid.';
        END IF;
        IF NEW.state = 'review_required' AND (NEW.telegram_message_id IS NOT NULL OR NEW.retry_after_seconds IS NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery retry-after evidence is invalid.';
        END IF;
        IF NEW.state IN ('failed_final','uncertain') AND (NEW.telegram_message_id IS NOT NULL OR NEW.retry_after_seconds IS NOT NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery failure evidence is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery state transition is forbidden.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER tg_c2c_protected_deliveries_delete_guard
BEFORE DELETE ON telegram_c2c_protected_deliveries
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram C2C protected delivery evidence is non-deletable.';
END
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_c2c_protected_deliveries')
            && DB::table('telegram_c2c_protected_deliveries')->exists()) {
            throw new RuntimeException('Cannot roll back Telegram C2C protected delivery authority after evidence exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS tg_c2c_protected_deliveries_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS tg_c2c_protected_deliveries_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS tg_c2c_protected_deliveries_insert_guard');
        Schema::dropIfExists('telegram_c2c_protected_deliveries');
    }
};
