<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-013 DAT-002 DAT-003 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('service_notification_preferences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique('snp_public_uq');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('service_subscription_id')->nullable();
            $table->foreign('service_subscription_id', 'snp_service_fk')->references('id')->on('service_subscriptions')->restrictOnDelete();
            $table->char('scope_key_hash', 64);
            $table->string('notification_type', 32);
            $table->string('threshold_code', 64)->default('*');
            $table->string('audience', 32)->default('customer');
            $table->string('destination', 32)->default('telegram');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('version')->default(1);
            $table->char('last_request_key_hash', 64);
            $table->string('last_correlation_id', 64);
            $table->timestamps(6);
            $table->unique(
                ['scope_key_hash', 'notification_type', 'threshold_code', 'audience', 'destination'],
                'snp_scope_type_threshold_uq',
            );
            $table->index(['owner_user_id', 'service_subscription_id'], 'snp_owner_service_idx');
        });

        Schema::create('service_notification_preference_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('service_notification_preference_id');
            $table->foreign('service_notification_preference_id', 'snph_preference_fk')
                ->references('id')->on('service_notification_preferences')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('enabled');
            $table->char('request_key_hash', 64)->unique('snph_request_uq');
            $table->char('payload_hash', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['service_notification_preference_id', 'version'], 'snph_preference_version_uq');
        });

        foreach ([
            "ALTER TABLE service_notification_preferences ADD CONSTRAINT snp_scope_hash_chk CHECK (scope_key_hash REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_notification_preferences ADD CONSTRAINT snp_type_chk CHECK (notification_type IN ('expiry','usage','low_balance','renewal_failure','service_state','sync_issue'))",
            "ALTER TABLE service_notification_preferences ADD CONSTRAINT snp_threshold_chk CHECK (threshold_code = '*' OR threshold_code REGEXP '^[a-z][a-z0-9_.-]{0,63}$')",
            "ALTER TABLE service_notification_preferences ADD CONSTRAINT snp_surface_chk CHECK (audience = 'customer' AND destination = 'telegram')",
            'ALTER TABLE service_notification_preferences ADD CONSTRAINT snp_version_chk CHECK (version >= 1)',
            "ALTER TABLE service_notification_preferences ADD CONSTRAINT snp_request_chk CHECK (last_request_key_hash REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_notification_preferences ADD CONSTRAINT snp_correlation_chk CHECK (last_correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$')",
            "ALTER TABLE service_notification_preference_histories ADD CONSTRAINT snph_request_chk CHECK (request_key_hash REGEXP '^[0-9a-f]{64}$' AND payload_hash REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_notification_preference_histories ADD CONSTRAINT snph_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$')",
        ] as $statement) {
            DB::statement($statement);
        }

        $this->installGuards();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_notification_preference_histories')
            && DB::table('service_notification_preference_histories')->exists()) {
            throw new RuntimeException('Cannot roll back Service notification preferences while durable preference history exists.');
        }
        if (Schema::hasTable('service_notification_preferences')
            && DB::table('service_notification_preferences')->exists()) {
            throw new RuntimeException('Cannot roll back Service notification preferences while preferences exist.');
        }

        $this->dropGuards();
        Schema::dropIfExists('service_notification_preference_histories');
        Schema::dropIfExists('service_notification_preferences');
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_preferences_insert_guard
BEFORE INSERT ON service_notification_preferences
FOR EACH ROW
BEGIN
    DECLARE valid_service_count INT DEFAULT 0;
    DECLARE expected_scope CHAR(64) DEFAULT NULL;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_preference_authority, '') <> 'service_notification_preference_write_v1'
       OR NEW.owner_user_id <> COALESCE(@app_service_notification_preference_actor_user_id, 0)
       OR NOT (NEW.service_subscription_id <=> @app_service_notification_preference_service_id)
       OR BINARY NEW.scope_key_hash <> BINARY COALESCE(@app_service_notification_preference_scope_hash, '')
       OR BINARY NEW.notification_type <> BINARY COALESCE(@app_service_notification_preference_type, '')
       OR BINARY NEW.threshold_code <> BINARY COALESCE(@app_service_notification_preference_threshold, '')
       OR NEW.enabled <> COALESCE(@app_service_notification_preference_enabled, -1)
       OR BINARY NEW.last_request_key_hash <> BINARY COALESCE(@app_service_notification_preference_request_hash, '')
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_preference_correlation_id, '')
       OR NEW.created_at <> @app_service_notification_preference_timestamp
       OR NEW.updated_at <> @app_service_notification_preference_timestamp
       OR NEW.version <> 1
       OR NEW.audience <> 'customer'
       OR NEW.destination <> 'telegram' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference creation authority is invalid.';
    END IF;

    IF NEW.service_subscription_id IS NULL THEN
        SET expected_scope = SHA2(CONCAT('service-notification-preference-global-v1|', NEW.owner_user_id), 256);
    ELSE
        SELECT COUNT(*) INTO valid_service_count
        FROM service_subscriptions service_row
        WHERE service_row.id = NEW.service_subscription_id
          AND service_row.user_id = NEW.owner_user_id;
        IF valid_service_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference ownership is invalid.';
        END IF;
        SET expected_scope = SHA2(CONCAT('service-notification-preference-service-v1|', NEW.owner_user_id, '|', NEW.service_subscription_id), 256);
    END IF;
    IF BINARY NEW.scope_key_hash <> BINARY expected_scope THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference scope is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_preferences_update_guard
BEFORE UPDATE ON service_notification_preferences
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_preference_authority, '') <> 'service_notification_preference_write_v1'
       OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR OLD.owner_user_id <> NEW.owner_user_id
       OR NOT (OLD.service_subscription_id <=> NEW.service_subscription_id)
       OR BINARY OLD.scope_key_hash <> BINARY NEW.scope_key_hash
       OR BINARY OLD.notification_type <> BINARY NEW.notification_type
       OR BINARY OLD.threshold_code <> BINARY NEW.threshold_code
       OR BINARY OLD.audience <> BINARY NEW.audience
       OR BINARY OLD.destination <> BINARY NEW.destination
       OR OLD.created_at <> NEW.created_at
       OR NEW.owner_user_id <> COALESCE(@app_service_notification_preference_actor_user_id, 0)
       OR NOT (NEW.service_subscription_id <=> @app_service_notification_preference_service_id)
       OR BINARY NEW.scope_key_hash <> BINARY COALESCE(@app_service_notification_preference_scope_hash, '')
       OR BINARY NEW.notification_type <> BINARY COALESCE(@app_service_notification_preference_type, '')
       OR BINARY NEW.threshold_code <> BINARY COALESCE(@app_service_notification_preference_threshold, '')
       OR NEW.enabled <> COALESCE(@app_service_notification_preference_enabled, -1)
       OR BINARY NEW.last_request_key_hash <> BINARY COALESCE(@app_service_notification_preference_request_hash, '')
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_preference_correlation_id, '')
       OR NEW.updated_at <> @app_service_notification_preference_timestamp
       OR NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference update authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_preferences_delete_guard
BEFORE DELETE ON service_notification_preferences
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preferences are durable and cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_preference_histories_insert_guard
BEFORE INSERT ON service_notification_preference_histories
FOR EACH ROW
BEGIN
    DECLARE valid_preference_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_preference_authority, '') <> 'service_notification_preference_write_v1'
       OR NEW.actor_user_id <> COALESCE(@app_service_notification_preference_actor_user_id, 0)
       OR NEW.enabled <> COALESCE(@app_service_notification_preference_enabled, -1)
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_service_notification_preference_request_hash, '')
       OR BINARY NEW.payload_hash <> BINARY COALESCE(@app_service_notification_preference_payload_hash, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_notification_preference_correlation_id, '')
       OR NEW.created_at <> @app_service_notification_preference_timestamp THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference history authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_preference_count
    FROM service_notification_preferences preference_row
    WHERE preference_row.id = NEW.service_notification_preference_id
      AND preference_row.owner_user_id = NEW.actor_user_id
      AND preference_row.enabled = NEW.enabled
      AND preference_row.version = NEW.version
      AND BINARY preference_row.scope_key_hash = BINARY COALESCE(@app_service_notification_preference_scope_hash, '')
      AND BINARY preference_row.notification_type = BINARY COALESCE(@app_service_notification_preference_type, '')
      AND BINARY preference_row.threshold_code = BINARY COALESCE(@app_service_notification_preference_threshold, '')
      AND BINARY preference_row.last_request_key_hash = BINARY NEW.request_key_hash
      AND BINARY preference_row.last_correlation_id = BINARY NEW.correlation_id;
    IF valid_preference_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference history does not match current authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_preference_histories_update_guard
BEFORE UPDATE ON service_notification_preference_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference history is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_preference_histories_delete_guard
BEFORE DELETE ON service_notification_preference_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification preference history is immutable.';
END
SQL);
    }

    private function dropGuards(): void
    {
        foreach ([
            'DROP TRIGGER IF EXISTS service_notification_preference_histories_delete_guard',
            'DROP TRIGGER IF EXISTS service_notification_preference_histories_update_guard',
            'DROP TRIGGER IF EXISTS service_notification_preference_histories_insert_guard',
            'DROP TRIGGER IF EXISTS service_notification_preferences_delete_guard',
            'DROP TRIGGER IF EXISTS service_notification_preferences_update_guard',
            'DROP TRIGGER IF EXISTS service_notification_preferences_insert_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }
    }
};
