<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-002 SVC-014 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-003 SEC-008 QUA-004 QUA-007 QUA-010 */
    public function up(): void
    {
        foreach (['audit_logs', 'service_delivery_attempts', 'service_subscriptions', 'users', 'administrators'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service delivery resend audit prerequisites are incomplete.');
            }
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER audit_logs_service_delivery_resend_insert_guard
BEFORE INSERT ON audit_logs
FOR EACH ROW
BEGIN
    DECLARE valid_attempt_count INT DEFAULT 0;

    IF BINARY NEW.action = BINARY 'service.delivery.resend.accepted' THEN
        IF COALESCE(@app_service_delivery_resend_audit_authority, '') <> 'service_delivery_resend_audit_v1'
           OR NEW.target_type IS NULL
           OR BINARY NEW.target_type <> BINARY 'service_subscription'
           OR NEW.target_id IS NULL
           OR NEW.request_fingerprint IS NULL
           OR CHAR_LENGTH(NEW.request_fingerprint) <> 64
           OR BINARY NEW.request_fingerprint <> BINARY COALESCE(@app_service_delivery_resend_request_hash, '')
           OR NEW.actor_type NOT IN ('administrator', 'user')
           OR NEW.actor_id IS NULL
           OR NEW.actor_id NOT REGEXP '^[1-9][0-9]*$'
           OR NEW.reason_code IS NULL
           OR NEW.reason_code NOT REGEXP '^[a-z0-9_.-]{1,64}$'
           OR NEW.reason IS NULL
           OR CHAR_LENGTH(TRIM(NEW.reason)) = 0
           OR CHAR_LENGTH(NEW.reason) > 1000
           OR NEW.before_safe_data IS NULL
           OR NEW.after_safe_data IS NULL
           OR JSON_VALID(NEW.before_safe_data) <> 1
           OR JSON_VALID(NEW.after_safe_data) <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery resend caller audit shape is invalid.';
        END IF;

        SELECT COUNT(*) INTO valid_attempt_count
        FROM service_delivery_attempts attempt_row
        INNER JOIN service_subscriptions service_row
            ON service_row.id = attempt_row.service_subscription_id
        INNER JOIN outbox_messages outbox_row
            ON BINARY outbox_row.id = BINARY attempt_row.outbox_event_id
        WHERE BINARY attempt_row.public_id = BINARY COALESCE(@app_service_delivery_resend_attempt_public_id, '')
          AND BINARY attempt_row.purpose = BINARY 'resend'
          AND BINARY attempt_row.request_key_hash = BINARY NEW.request_fingerprint
          AND BINARY attempt_row.correlation_id = BINARY NEW.correlation_id
          AND BINARY service_row.public_id = BINARY NEW.target_id
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_state'))
              IN (BINARY 'active', BINARY 'suspended')
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_version')) AS UNSIGNED)
              = attempt_row.target_lifecycle_version
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED)
              = attempt_row.target_remote_identity_generation
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.delivery_attempt_public_id'))
              = BINARY attempt_row.public_id
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.delivery_purpose')) = BINARY 'resend'
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.target_remote_identity_generation')) AS UNSIGNED)
              = attempt_row.target_remote_identity_generation
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.target_lifecycle_version')) AS UNSIGNED)
              = attempt_row.target_lifecycle_version
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.outbox_event_id'))
              = BINARY attempt_row.outbox_event_id
          AND BINARY outbox_row.event_type = BINARY 'provisioning.service_delivery.requested'
          AND BINARY outbox_row.aggregate_type = BINARY 'service_delivery_attempt'
          AND BINARY outbox_row.aggregate_id = BINARY attempt_row.public_id
          AND BINARY outbox_row.correlation_id = BINARY attempt_row.correlation_id
          AND outbox_row.dispatch_state = 'pending'
          AND (
              (NEW.actor_type = 'user'
                  AND CAST(NEW.actor_id AS UNSIGNED) = service_row.user_id
                  AND EXISTS (
                      SELECT 1 FROM users user_row
                      WHERE user_row.id = service_row.user_id
                        AND user_row.account_status = 'active'
                        AND user_row.account_type IN ('customer', 'agent')
                  ))
              OR (NEW.actor_type = 'administrator'
                  AND EXISTS (
                      SELECT 1 FROM administrators administrator_row
                      WHERE administrator_row.id = CAST(NEW.actor_id AS UNSIGNED)
                        AND administrator_row.status = 'active'
                  ))
          );

        IF valid_attempt_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery resend caller audit is detached from the exact Delivery Attempt.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER audit_logs_service_delivery_resend_update_guard
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    IF BINARY OLD.action = BINARY 'service.delivery.resend.accepted'
       OR BINARY NEW.action = BINARY 'service.delivery.resend.accepted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery resend caller audit is immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER audit_logs_service_delivery_resend_delete_guard
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    IF BINARY OLD.action = BINARY 'service.delivery.resend.accepted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery resend caller audit is non-deletable.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs')
            && DB::table('audit_logs')->where('action', 'service.delivery.resend.accepted')->exists()) {
            throw new RuntimeException('Cannot roll back Service delivery resend audit authority after caller audit evidence exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_delivery_resend_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_delivery_resend_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_delivery_resend_insert_guard');
    }
};
