<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-013 SVC-014 ARCH-003 ARCH-004 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        foreach ([
            'service_subscriptions',
            'service_delivery_attempts',
            'service_delivery_effects',
            'service_sync_snapshots',
            'service_auto_renew_notification_intents',
            'service_operational_authority_capability',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service notification authority requires the accepted Service, delivery, synchronization, and renewal foundations.');
            }
        }

        $this->resetInterruptedInstallIfSafe();
        $this->installDeliveryPurposeConstraint();

        Schema::create('service_notification_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique('sns_public_uq');
            $table->foreignId('service_subscription_id');
            $table->foreign('service_subscription_id', 'sns_service_fk')->references('id')->on('service_subscriptions')->restrictOnDelete();
            $table->char('episode_key_hash', 64)->unique('sns_episode_uq');
            $table->string('notification_type', 32);
            $table->string('threshold_code', 64);
            $table->char('cycle_key_hash', 64);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('state', 24);
            $table->foreignId('latest_delivery_attempt_id')->nullable();
            $table->foreign('latest_delivery_attempt_id', 'sns_attempt_fk')->references('id')->on('service_delivery_attempts')->restrictOnDelete();
            $table->unsignedSmallInteger('latest_retry_ordinal')->nullable();
            $table->dateTime('next_retry_at', 6)->nullable();
            $table->dateTime('triggered_at', 6);
            $table->dateTime('notified_at', 6)->nullable();
            $table->dateTime('acknowledged_at', 6)->nullable();
            $table->dateTime('escalated_at', 6)->nullable();
            $table->dateTime('expired_at', 6)->nullable();
            $table->string('last_correlation_id', 64);
            $table->dateTime('updated_at', 6);
            $table->index(['service_subscription_id', 'state', 'updated_at'], 'sns_service_state_idx');
            $table->index(['notification_type', 'state', 'next_retry_at'], 'sns_type_retry_idx');
        });

        Schema::create('service_notification_delivery_bindings', function (Blueprint $table): void {
            $table->foreignId('service_delivery_attempt_id')->primary();
            $table->foreign('service_delivery_attempt_id', 'sndb_attempt_fk')->references('id')->on('service_delivery_attempts')->restrictOnDelete();
            $table->foreignId('service_notification_state_id');
            $table->foreign('service_notification_state_id', 'sndb_state_fk')->references('id')->on('service_notification_states')->restrictOnDelete();
            $table->unsignedSmallInteger('retry_ordinal');
            $table->text('presentation_text');
            $table->char('presentation_hash', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['service_notification_state_id', 'retry_ordinal'], 'sndb_state_retry_uq');
        });

        Schema::create('service_notification_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('service_notification_state_id');
            $table->foreign('service_notification_state_id', 'sne_state_fk')->references('id')->on('service_notification_states')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 32);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24);
            $table->foreignId('service_delivery_attempt_id')->nullable();
            $table->foreign('service_delivery_attempt_id', 'sne_attempt_fk')->references('id')->on('service_delivery_attempts')->restrictOnDelete();
            $table->unsignedSmallInteger('retry_ordinal')->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['service_notification_state_id', 'sequence'], 'sne_state_sequence_uq');
        });

        $this->installConstraints();
        $this->installGuards();
    }

    public function down(): void
    {
        foreach ([
            'service_notification_events',
            'service_notification_delivery_bindings',
            'service_notification_states',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Service notification authority while durable notification evidence exists.');
            }
        }
        if (Schema::hasTable('service_delivery_attempts')
            && DB::table('service_delivery_attempts')->where('purpose', 'notification')->exists()) {
            throw new RuntimeException('Cannot roll back Service notification authority while notification Delivery Attempts exist.');
        }

        $this->dropGuards();
        Schema::dropIfExists('service_notification_events');
        Schema::dropIfExists('service_notification_delivery_bindings');
        Schema::dropIfExists('service_notification_states');
        $this->restoreDeliveryPurposeConstraint();
    }

    private function resetInterruptedInstallIfSafe(): void
    {
        $tables = [
            'service_notification_states',
            'service_notification_delivery_bindings',
            'service_notification_events',
        ];
        $existing = array_values(array_filter(
            $tables,
            static fn (string $table): bool => Schema::hasTable($table),
        ));
        if ($existing === []) {
            return;
        }

        foreach ($existing as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Service notification migration cannot repair an interrupted install after authority rows exist.');
            }
        }

        $this->dropGuards();
        Schema::dropIfExists('service_notification_events');
        Schema::dropIfExists('service_notification_delivery_bindings');
        Schema::dropIfExists('service_notification_states');
    }

    private function installDeliveryPurposeConstraint(): void
    {
        DB::statement('ALTER TABLE service_delivery_attempts DROP CONSTRAINT service_delivery_attempts_purpose_chk');
        DB::statement("ALTER TABLE service_delivery_attempts ADD CONSTRAINT service_delivery_attempts_purpose_chk CHECK (purpose IN ('initial','resend','notification'))");
    }

    private function restoreDeliveryPurposeConstraint(): void
    {
        DB::statement('ALTER TABLE service_delivery_attempts DROP CONSTRAINT service_delivery_attempts_purpose_chk');
        DB::statement("ALTER TABLE service_delivery_attempts ADD CONSTRAINT service_delivery_attempts_purpose_chk CHECK (purpose IN ('initial','resend'))");
    }

    private function installConstraints(): void
    {
        foreach ([
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_type_chk CHECK (notification_type IN ('expiry','low_balance','renewal_failure'))",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_state_chk CHECK (state IN ('triggered','notified','acknowledged','escalated','expired'))",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_hash_chk CHECK (episode_key_hash REGEXP '^[0-9a-f]{64}$' AND cycle_key_hash REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_correlation_chk CHECK (last_correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$')",
            'ALTER TABLE service_notification_states ADD CONSTRAINT sns_attempt_retry_chk CHECK ((latest_delivery_attempt_id IS NULL AND latest_retry_ordinal IS NULL) OR (latest_delivery_attempt_id IS NOT NULL AND latest_retry_ordinal IS NOT NULL))',
            "ALTER TABLE service_notification_delivery_bindings ADD CONSTRAINT sndb_hash_chk CHECK (presentation_hash REGEXP '^[0-9a-f]{64}$' AND CHAR_LENGTH(presentation_text) BETWEEN 1 AND 4096)",
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_type_chk CHECK (event_type IN ('triggered','delivery_queued','retry_scheduled','notified','acknowledged','escalated','expired'))",
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_state_chk CHECK ((from_state IS NULL OR from_state IN ('triggered','notified','acknowledged','escalated','expired')) AND to_state IN ('triggered','notified','acknowledged','escalated','expired'))",
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$')",
            'ALTER TABLE service_notification_events ADD CONSTRAINT sne_attempt_retry_chk CHECK ((service_delivery_attempt_id IS NULL AND retry_ordinal IS NULL) OR (service_delivery_attempt_id IS NOT NULL AND retry_ordinal IS NOT NULL))',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_insert_guard
BEFORE INSERT ON service_notification_states
FOR EACH ROW
BEGIN
    DECLARE valid_service_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') <> 'service_notification_create_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_notification_service_id, 0)
       OR BINARY NEW.episode_key_hash <> BINARY COALESCE(@app_service_notification_episode_key, '')
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '')
       OR NEW.state <> 'triggered'
       OR NEW.latest_delivery_attempt_id IS NOT NULL OR NEW.latest_retry_ordinal IS NOT NULL
       OR NEW.next_retry_at IS NOT NULL OR NEW.notified_at IS NOT NULL OR NEW.acknowledged_at IS NOT NULL
       OR NEW.escalated_at IS NOT NULL OR NEW.expired_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state creation authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_service_count
    FROM service_subscriptions service_row
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.provisioned_at IS NOT NULL
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended');

    IF valid_service_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state requires one live provisioned Service.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_update_guard
BEFORE UPDATE ON service_notification_states
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR NEW.id <> COALESCE(@app_service_notification_state_id, 0)
       OR NEW.service_subscription_id <> COALESCE(@app_service_notification_service_id, 0)
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '')
       OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR OLD.service_subscription_id <> NEW.service_subscription_id
       OR BINARY OLD.episode_key_hash <> BINARY NEW.episode_key_hash
       OR BINARY OLD.notification_type <> BINARY NEW.notification_type
       OR BINARY OLD.threshold_code <> BINARY NEW.threshold_code
       OR BINARY OLD.cycle_key_hash <> BINARY NEW.cycle_key_hash
       OR BINARY OLD.source_type <> BINARY NEW.source_type
       OR NOT (OLD.source_id <=> NEW.source_id)
       OR OLD.triggered_at <> NEW.triggered_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state identity is immutable.';
    END IF;

    IF COALESCE(@app_service_notification_authority, '') = 'service_notification_bind_v1' THEN
        IF OLD.state <> 'triggered' OR NEW.state <> 'triggered'
           OR NEW.latest_delivery_attempt_id <> COALESCE(@app_service_notification_attempt_id, 0)
           OR NEW.latest_retry_ordinal <> COALESCE(@app_service_notification_retry_ordinal, 65535)
           OR NEW.next_retry_at IS NOT NULL
           OR NOT (OLD.notified_at <=> NEW.notified_at)
           OR NOT (OLD.acknowledged_at <=> NEW.acknowledged_at)
           OR NOT (OLD.escalated_at <=> NEW.escalated_at)
           OR NOT (OLD.expired_at <=> NEW.expired_at)
           OR (OLD.latest_retry_ordinal IS NULL AND NEW.latest_retry_ordinal <> 0)
           OR (OLD.latest_retry_ordinal IS NOT NULL AND NEW.latest_retry_ordinal <> OLD.latest_retry_ordinal + 1) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding transition is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_update_v1' THEN
        IF NOT (OLD.latest_delivery_attempt_id <=> NEW.latest_delivery_attempt_id)
           OR NOT (OLD.latest_retry_ordinal <=> NEW.latest_retry_ordinal)
           OR NOT (
               (OLD.state = 'triggered' AND NEW.state IN ('triggered','notified','escalated','expired'))
               OR (OLD.state IN ('notified','escalated') AND NEW.state = 'acknowledged')
           ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state transition is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state update authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_delete_guard
BEFORE DELETE ON service_notification_states
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state evidence is non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_bindings_insert_guard
BEFORE INSERT ON service_notification_delivery_bindings
FOR EACH ROW
BEGIN
    DECLARE valid_binding_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') <> 'service_notification_bind_v1'
       OR NEW.service_notification_state_id <> COALESCE(@app_service_notification_state_id, 0)
       OR NEW.service_delivery_attempt_id <> COALESCE(@app_service_notification_attempt_id, 0)
       OR NEW.retry_ordinal <> COALESCE(@app_service_notification_retry_ordinal, 65535)
       OR BINARY NEW.presentation_hash <> BINARY LOWER(SHA2(NEW.presentation_text, 256)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_binding_count
    FROM service_notification_states state_row
    JOIN service_delivery_attempts attempt_row ON attempt_row.id = NEW.service_delivery_attempt_id
    WHERE state_row.id = NEW.service_notification_state_id
      AND state_row.service_subscription_id = COALESCE(@app_service_notification_service_id, 0)
      AND state_row.state = 'triggered'
      AND state_row.latest_delivery_attempt_id = attempt_row.id
      AND state_row.latest_retry_ordinal = NEW.retry_ordinal
      AND attempt_row.service_subscription_id = state_row.service_subscription_id
      AND BINARY attempt_row.purpose = BINARY 'notification'
      AND BINARY attempt_row.correlation_id = BINARY COALESCE(@app_service_notification_correlation_id, '');

    IF valid_binding_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding must match the current notification Delivery Attempt.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_bindings_update_guard
BEFORE UPDATE ON service_notification_delivery_bindings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_bindings_delete_guard
BEFORE DELETE ON service_notification_delivery_bindings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding is non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_events_insert_guard
BEFORE INSERT ON service_notification_events
FOR EACH ROW
BEGIN
    DECLARE current_state VARCHAR(24) DEFAULT NULL;
    DECLARE expected_sequence INT DEFAULT 1;
    DECLARE valid_delivery_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') NOT IN (
        'service_notification_update_v1','service_notification_bind_v1'
    ) OR NEW.service_notification_state_id <> COALESCE(@app_service_notification_state_id, 0)
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification event authority is invalid.';
    END IF;

    SELECT state_row.state INTO current_state
    FROM service_notification_states state_row
    WHERE state_row.id = NEW.service_notification_state_id
      AND state_row.service_subscription_id = COALESCE(@app_service_notification_service_id, 0)
    LIMIT 1;

    SELECT COALESCE(MAX(event_row.sequence), 0) + 1 INTO expected_sequence
    FROM service_notification_events event_row
    WHERE event_row.service_notification_state_id = NEW.service_notification_state_id;

    IF current_state IS NULL OR BINARY NEW.to_state <> BINARY current_state OR NEW.sequence <> expected_sequence THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification event state or sequence is invalid.';
    END IF;

    IF NEW.event_type = 'delivery_queued' THEN
        SELECT COUNT(*) INTO valid_delivery_count
        FROM service_notification_delivery_bindings binding_row
        WHERE binding_row.service_notification_state_id = NEW.service_notification_state_id
          AND binding_row.service_delivery_attempt_id = NEW.service_delivery_attempt_id
          AND binding_row.retry_ordinal = NEW.retry_ordinal;
        IF valid_delivery_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery event requires the exact delivery binding.';
        END IF;
    ELSEIF NEW.service_delivery_attempt_id IS NOT NULL OR NEW.retry_ordinal IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification non-delivery events cannot bind a Delivery Attempt.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_events_update_guard
BEFORE UPDATE ON service_notification_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification events are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_events_delete_guard
BEFORE DELETE ON service_notification_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification events are non-deletable.';
END
SQL);
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_events_delete_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_events_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_events_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_bindings_delete_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_bindings_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_bindings_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_states_delete_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_states_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_states_insert_guard`');
    }
};
