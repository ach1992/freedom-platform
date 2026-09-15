<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_initial_provision_envelope_insert_guard
BEFORE INSERT ON outbox_messages
FOR EACH ROW
BEGIN
    IF LOWER(NEW.event_type) = 'provisioning.initial.requested' THEN
        IF HEX(NEW.event_type) <> HEX('provisioning.initial.requested')
           OR COALESCE(JSON_TYPE(NEW.payload), '') <> 'OBJECT'
           OR COALESCE(JSON_LENGTH(NEW.payload), -1) <> 4
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')), '')))
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')), '')))
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')), '')))
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')), '')))
           OR HEX(CAST(NEW.payload AS CHAR)) <> HEX(CONCAT(
                '{"order_item_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')),
                '","order_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')),
                '","provisioning_operation_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')),
                '","service_subscription_public_id":"', JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')),
                '"}'
           ))
           OR HEX(NEW.payload_hash) <> HEX(LOWER(SHA2(CAST(NEW.payload AS CHAR), 256)))
           OR HEX(NEW.event_key) <> HEX(CONCAT(
                'provisioning.initial.requested:',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id'))
           ))
           OR HEX(NEW.aggregate_type) <> HEX('provisioning_operation')
           OR HEX(NEW.aggregate_id) <> HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id'))) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox command must use the exact canonical safe envelope.';
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

        if (Schema::hasTable('service_subscriptions') && Schema::hasTable('provisioning_operations')) {
            $this->createAuthorityAwareUpdateGuard();
        } else {
            $this->createFailClosedUpdateGuard();
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_initial_provision_envelope_delete_guard
BEFORE DELETE ON outbox_messages
FOR EACH ROW
BEGIN
    IF HEX(OLD.event_type) = HEX('provisioning.initial.requested') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox commands are non-deletable.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_initial_provision_envelope_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_initial_provision_envelope_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_initial_provision_envelope_insert_guard');
    }

    private function createFailClosedUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_initial_provision_envelope_update_guard
BEFORE UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    IF HEX(OLD.event_type) = HEX('provisioning.initial.requested') THEN
        IF HEX(OLD.id) <> HEX(NEW.id)
           OR HEX(OLD.event_key) <> HEX(NEW.event_key)
           OR HEX(OLD.event_type) <> HEX(NEW.event_type)
           OR HEX(OLD.aggregate_type) <> HEX(NEW.aggregate_type)
           OR HEX(OLD.aggregate_id) <> HEX(NEW.aggregate_id)
           OR HEX(CAST(OLD.payload AS CHAR)) <> HEX(CAST(NEW.payload AS CHAR))
           OR HEX(OLD.payload_hash) <> HEX(NEW.payload_hash)
           OR HEX(OLD.correlation_id) <> HEX(NEW.correlation_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox command identity is immutable.';
        END IF;

        IF HEX(NEW.dispatch_state) NOT IN (
            HEX('authority_pending'), HEX('pending'), HEX('leased'), HEX('retry'), HEX('processed'), HEX('review_required')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox must use an exact dispatch lifecycle state.';
        END IF;

        IF OLD.dispatch_state = 'authority_pending' AND NEW.dispatch_state <> 'authority_pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox dispatch remains quarantined until final queue authority is installed.';
        ELSEIF OLD.dispatch_state <> 'authority_pending' AND NEW.dispatch_state = 'authority_pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox dispatch lifecycle cannot return to authority_pending.';
        END IF;
    ELSEIF LOWER(NEW.event_type) = 'provisioning.initial.requested' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Outbox rows cannot be converted into initial provisioning commands.';
    END IF;
END
SQL);
    }

    private function createAuthorityAwareUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_initial_provision_envelope_update_guard
BEFORE UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    DECLARE final_authority_count INT DEFAULT 0;

    IF HEX(OLD.event_type) = HEX('provisioning.initial.requested') THEN
        IF HEX(OLD.id) <> HEX(NEW.id)
           OR HEX(OLD.event_key) <> HEX(NEW.event_key)
           OR HEX(OLD.event_type) <> HEX(NEW.event_type)
           OR HEX(OLD.aggregate_type) <> HEX(NEW.aggregate_type)
           OR HEX(OLD.aggregate_id) <> HEX(NEW.aggregate_id)
           OR HEX(CAST(OLD.payload AS CHAR)) <> HEX(CAST(NEW.payload AS CHAR))
           OR HEX(OLD.payload_hash) <> HEX(NEW.payload_hash)
           OR HEX(OLD.correlation_id) <> HEX(NEW.correlation_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox command identity is immutable.';
        END IF;

        IF HEX(NEW.dispatch_state) NOT IN (
            HEX('authority_pending'), HEX('pending'), HEX('leased'), HEX('retry'), HEX('processed'), HEX('review_required')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox must use an exact dispatch lifecycle state.';
        END IF;

        IF OLD.dispatch_state = 'authority_pending' AND NEW.dispatch_state <> 'authority_pending' THEN
            IF HEX(NEW.dispatch_state) <> HEX('pending')
               OR NEW.processed_at IS NOT NULL
               OR NEW.lease_token IS NOT NULL
               OR NEW.leased_until IS NOT NULL
               OR NEW.attempts <> 0
               OR NEW.review_reason IS NOT NULL
               OR NEW.last_error_class IS NOT NULL
               OR NEW.last_error_code IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox authority can only release into a clean pending dispatch state.';
            END IF;

            SELECT COUNT(*) INTO final_authority_count
            FROM orders order_row
            INNER JOIN order_items item_row
                ON item_row.order_id = order_row.id
               AND item_row.line_number = 1
            INNER JOIN service_subscriptions service_row
                ON service_row.order_id = order_row.id
               AND service_row.order_item_id = item_row.id
               AND service_row.user_id = order_row.user_id
            INNER JOIN provisioning_operations operation_row
                ON operation_row.order_id = order_row.id
               AND operation_row.order_item_id = item_row.id
               AND operation_row.service_subscription_id = service_row.id
               AND operation_row.user_id = order_row.user_id
               AND operation_row.operation_type = 'initial_provision'
               AND operation_row.operation_key = CONCAT('initial-provision:', item_row.public_id)
               AND operation_row.state = 'queued'
               AND operation_row.state_version = 1
            WHERE order_row.state = 'provisioning_queued'
              AND order_row.state_version = 2
              AND HEX(order_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')))
              AND HEX(item_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')))
              AND HEX(service_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')))
              AND HEX(operation_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')))
              AND HEX(operation_row.public_id) = HEX(NEW.aggregate_id)
              AND HEX(operation_row.correlation_id) = HEX(NEW.correlation_id);

            IF final_authority_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox dispatch requires the exact final queued local authority.';
            END IF;
        ELSEIF OLD.dispatch_state <> 'authority_pending' AND NEW.dispatch_state = 'authority_pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox dispatch lifecycle cannot return to authority_pending.';
        END IF;
    ELSEIF LOWER(NEW.event_type) = 'provisioning.initial.requested' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Outbox rows cannot be converted into initial provisioning commands.';
    END IF;
END
SQL);
    }
};
