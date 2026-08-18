<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-002 SVC-014 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 QUA-007 QUA-010 */
    public function up(): void
    {
        if (! Schema::hasTable('service_subscriptions')
            || ! Schema::hasTable('provisioning_operations')
            || ! Schema::hasTable('outbox_messages')) {
            throw new RuntimeException('Service Delivery Attempt authority requires Service Subscription, mutation, and Transactional Outbox foundations.');
        }

        if (! Schema::hasTable('service_delivery_attempts')) {
            DB::statement(<<<'SQL'
CREATE TABLE service_delivery_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    service_subscription_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(16) NOT NULL,
    request_key_hash CHAR(64) NOT NULL,
    correlation_id VARCHAR(64) NOT NULL,
    target_remote_identity_generation BIGINT UNSIGNED NOT NULL,
    target_lifecycle_version BIGINT UNSIGNED NOT NULL,
    outbox_event_id CHAR(36) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY service_delivery_attempts_public_id_unique (public_id),
    UNIQUE KEY service_delivery_attempts_service_request_unique (service_subscription_id, request_key_hash),
    UNIQUE KEY service_delivery_attempts_outbox_event_unique (outbox_event_id),
    KEY service_delivery_attempts_service_created_idx (service_subscription_id, created_at),
    CONSTRAINT service_delivery_attempts_service_fk FOREIGN KEY (service_subscription_id) REFERENCES service_subscriptions (id) ON DELETE RESTRICT,
    CONSTRAINT service_delivery_attempts_purpose_chk CHECK (purpose IN ('initial','resend')),
    CONSTRAINT service_delivery_attempts_public_id_chk CHECK (public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'),
    CONSTRAINT service_delivery_attempts_request_hash_chk CHECK (request_key_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT service_delivery_attempts_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$'),
    CONSTRAINT service_delivery_attempts_remote_generation_chk CHECK (target_remote_identity_generation >= 1),
    CONSTRAINT service_delivery_attempts_lifecycle_version_chk CHECK (target_lifecycle_version >= 0),
    CONSTRAINT service_delivery_attempts_outbox_event_chk CHECK (
        outbox_event_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    )
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);
        }

        $this->createOutboxInsertGuard();
        $this->createAttemptInsertGuard();
        $this->createAttemptImmutabilityGuards();
        $this->createOutboxUpdateGuard();
        $this->createOutboxDeleteGuard();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_delivery_attempts') && DB::table('service_delivery_attempts')->exists()) {
            throw new RuntimeException('Cannot roll back Service Delivery Attempt authority while delivery evidence exists.');
        }
        if (Schema::hasTable('outbox_messages') && DB::table('outbox_messages')
            ->where('event_type', 'provisioning.service_delivery.requested')->exists()) {
            throw new RuntimeException('Cannot roll back Service Delivery Attempt authority while delivery Outbox evidence exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS outbox_service_delivery_envelope_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_service_delivery_envelope_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_attempts_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_attempts_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_attempts_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_service_delivery_envelope_insert_guard');
        Schema::dropIfExists('service_delivery_attempts');
    }

    private function createOutboxInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_service_delivery_envelope_insert_guard
BEFORE INSERT ON outbox_messages
FOR EACH ROW
BEGIN
    IF LOWER(NEW.event_type) = 'provisioning.service_delivery.requested' THEN
        IF COALESCE(@app_service_delivery_authority, '') <> 'service_delivery_queue_v1'
           OR BINARY NEW.id <> BINARY COALESCE(@app_service_delivery_outbox_event_id, '')
           OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_delivery_correlation_id, '')
           OR HEX(NEW.event_type) <> HEX('provisioning.service_delivery.requested')
           OR COALESCE(JSON_TYPE(NEW.payload), '') <> 'OBJECT'
           OR COALESCE(JSON_LENGTH(NEW.payload), -1) <> 1
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.service_delivery_attempt_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_delivery_attempt_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_delivery_attempt_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_delivery_attempt_public_id')), '')))
           OR HEX(CAST(NEW.payload AS CHAR)) <> HEX(CONCAT(
                '{"service_delivery_attempt_public_id":"',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_delivery_attempt_public_id')),
                '"}'
           ))
           OR HEX(NEW.payload_hash) <> HEX(LOWER(SHA2(CAST(NEW.payload AS CHAR), 256)))
           OR HEX(NEW.event_key) <> HEX(CONCAT(
                'provisioning-service-delivery-requested:',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_delivery_attempt_public_id'))
           ))
           OR HEX(NEW.aggregate_type) <> HEX('service_delivery_attempt')
           OR HEX(NEW.aggregate_id) <> HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_delivery_attempt_public_id')))
           OR BINARY NEW.aggregate_id <> BINARY COALESCE(@app_service_delivery_attempt_public_id, '') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery Outbox command must use the exact canonical safe envelope.';
        END IF;

        SET NEW.dispatch_state = 'authority_pending';
        SET NEW.lease_token = NULL;
        SET NEW.leased_until = NULL;
        SET NEW.processed_at = NULL;
        SET NEW.attempts = 0;
        SET NEW.review_reason = NULL;
        SET NEW.last_error_class = NULL;
        SET NEW.last_error_code = NULL;
    END IF;
END
SQL);
    }

    private function createAttemptInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_attempts_insert_guard
BEFORE INSERT ON service_delivery_attempts
FOR EACH ROW
BEGIN
    DECLARE valid_service_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_outbox_count INT DEFAULT 0;
    DECLARE unresolved_mutation_count INT DEFAULT 0;

    IF COALESCE(@app_service_delivery_authority, '') <> 'service_delivery_queue_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_delivery_service_id, 0)
       OR BINARY NEW.purpose <> BINARY COALESCE(@app_service_delivery_purpose, '')
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_service_delivery_request_hash, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_delivery_correlation_id, '')
       OR BINARY NEW.public_id <> BINARY COALESCE(@app_service_delivery_attempt_public_id, '')
       OR BINARY NEW.outbox_event_id <> BINARY COALESCE(@app_service_delivery_outbox_event_id, '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt creation authority is invalid.';
    END IF;

    SELECT service_row.id INTO valid_service_id
    FROM service_subscriptions service_row
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.provisioned_at IS NOT NULL
      AND service_row.service_target_id IS NOT NULL
      AND service_row.remote_service_id IS NOT NULL
      AND CHAR_LENGTH(service_row.remote_service_id) > 0
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended')
      AND service_row.remote_identity_generation = NEW.target_remote_identity_generation
      AND service_row.lifecycle_version = NEW.target_lifecycle_version
    LIMIT 1 FOR UPDATE;

    IF valid_service_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt must match the current provisioned Service identity and lifecycle.';
    END IF;

    SELECT COUNT(*) INTO unresolved_mutation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.service_subscription_id = NEW.service_subscription_id
      AND operation_row.operation_type <> 'initial_provision'
      AND operation_row.state NOT IN ('succeeded','failed_final','compensated');

    IF unresolved_mutation_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt is blocked by an unresolved Service mutation.';
    END IF;

    SELECT COUNT(*) INTO valid_outbox_count
    FROM outbox_messages outbox_row
    WHERE BINARY outbox_row.id = BINARY NEW.outbox_event_id
      AND BINARY outbox_row.event_type = BINARY 'provisioning.service_delivery.requested'
      AND BINARY outbox_row.event_key = BINARY CONCAT('provisioning-service-delivery-requested:', NEW.public_id)
      AND BINARY outbox_row.aggregate_type = BINARY 'service_delivery_attempt'
      AND BINARY outbox_row.aggregate_id = BINARY NEW.public_id
      AND BINARY outbox_row.correlation_id = BINARY NEW.correlation_id
      AND outbox_row.dispatch_state = 'authority_pending'
      AND outbox_row.processed_at IS NULL
      AND outbox_row.lease_token IS NULL
      AND outbox_row.leased_until IS NULL
      AND outbox_row.attempts = 0
      AND outbox_row.review_reason IS NULL
      AND outbox_row.last_error_class IS NULL
      AND outbox_row.last_error_code IS NULL
      AND COALESCE(JSON_TYPE(outbox_row.payload), '') = 'OBJECT'
      AND COALESCE(JSON_LENGTH(outbox_row.payload), -1) = 1
      AND BINARY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.service_delivery_attempt_public_id')), '') = BINARY NEW.public_id
      AND HEX(CAST(outbox_row.payload AS CHAR)) = HEX(CONCAT('{"service_delivery_attempt_public_id":"', NEW.public_id, '"}'))
      AND HEX(outbox_row.payload_hash) = HEX(LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)));

    IF valid_outbox_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt requires one exact quarantined Outbox command.';
    END IF;
END
SQL);
    }

    private function createAttemptImmutabilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_attempts_update_guard
BEFORE UPDATE ON service_delivery_attempts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt intent evidence is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_attempts_delete_guard
BEFORE DELETE ON service_delivery_attempts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt evidence is non-deletable.';
END
SQL);
    }

    private function createOutboxUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_service_delivery_envelope_update_guard
BEFORE UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    DECLARE final_authority_count INT DEFAULT 0;

    IF HEX(OLD.event_type) = HEX('provisioning.service_delivery.requested') THEN
        IF HEX(OLD.id) <> HEX(NEW.id)
           OR HEX(OLD.event_key) <> HEX(NEW.event_key)
           OR HEX(OLD.event_type) <> HEX(NEW.event_type)
           OR HEX(OLD.aggregate_type) <> HEX(NEW.aggregate_type)
           OR HEX(OLD.aggregate_id) <> HEX(NEW.aggregate_id)
           OR HEX(CAST(OLD.payload AS CHAR)) <> HEX(CAST(NEW.payload AS CHAR))
           OR HEX(OLD.payload_hash) <> HEX(NEW.payload_hash)
           OR HEX(OLD.correlation_id) <> HEX(NEW.correlation_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery Outbox command identity is immutable.';
        END IF;

        IF HEX(NEW.dispatch_state) NOT IN (
            HEX('authority_pending'), HEX('pending'), HEX('leased'), HEX('retry'), HEX('processed'), HEX('review_required')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery Outbox command must use an exact dispatch lifecycle state.';
        END IF;

        IF OLD.dispatch_state = 'authority_pending' AND NEW.dispatch_state <> 'authority_pending' THEN
            IF COALESCE(@app_service_delivery_authority, '') <> 'service_delivery_queue_v1'
               OR BINARY NEW.id <> BINARY COALESCE(@app_service_delivery_outbox_event_id, '')
               OR BINARY NEW.aggregate_id <> BINARY COALESCE(@app_service_delivery_attempt_public_id, '')
               OR HEX(NEW.dispatch_state) <> HEX('pending')
               OR NEW.processed_at IS NOT NULL
               OR NEW.lease_token IS NOT NULL
               OR NEW.leased_until IS NOT NULL
               OR NEW.attempts <> 0
               OR NEW.review_reason IS NOT NULL
               OR NEW.last_error_class IS NOT NULL
               OR NEW.last_error_code IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery Outbox authority can only release into a clean pending state.';
            END IF;

            SELECT COUNT(*) INTO final_authority_count
            FROM service_delivery_attempts attempt_row
            WHERE BINARY attempt_row.outbox_event_id = BINARY NEW.id
              AND BINARY attempt_row.public_id = BINARY NEW.aggregate_id
              AND BINARY attempt_row.correlation_id = BINARY NEW.correlation_id
              AND attempt_row.service_subscription_id = COALESCE(@app_service_delivery_service_id, 0)
              AND BINARY attempt_row.purpose = BINARY COALESCE(@app_service_delivery_purpose, '')
              AND BINARY attempt_row.request_key_hash = BINARY COALESCE(@app_service_delivery_request_hash, '');

            IF final_authority_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery Outbox dispatch requires the exact final Delivery Attempt authority.';
            END IF;
        ELSEIF OLD.dispatch_state <> 'authority_pending' AND NEW.dispatch_state = 'authority_pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery Outbox lifecycle cannot return to authority_pending.';
        END IF;
    ELSEIF LOWER(NEW.event_type) = 'provisioning.service_delivery.requested' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Outbox rows cannot be converted into Service delivery commands.';
    END IF;
END
SQL);
    }

    private function createOutboxDeleteGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_service_delivery_envelope_delete_guard
BEFORE DELETE ON outbox_messages
FOR EACH ROW
BEGIN
    IF HEX(OLD.event_type) = HEX('provisioning.service_delivery.requested') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery Outbox commands are non-deletable.';
    END IF;
END
SQL);
    }
};
