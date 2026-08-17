<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-006 ARCH-003 DAT-003 SEC-002 SEC-003 SEC-008 QUA-004 */
    public function up(): void
    {
        foreach (['audit_logs', 'provisioning_operations', 'service_subscriptions', 'users', 'administrators'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service lifecycle command audit prerequisites are incomplete.');
            }
        }
        if (! Schema::hasColumn('provisioning_operations', 'request_key_hash')
            || ! Schema::hasColumn('provisioning_operations', 'operation_generation')) {
            throw new RuntimeException('Service mutation authority must be active before lifecycle command audit authority.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER audit_logs_service_lifecycle_insert_guard
BEFORE INSERT ON audit_logs
FOR EACH ROW
BEGIN
    DECLARE valid_operation_count INT DEFAULT 0;

    IF BINARY NEW.action = BINARY 'service.lifecycle.command.accepted' THEN
        IF COALESCE(@app_service_lifecycle_audit_authority, '') <> 'service_lifecycle_command_audit_v1'
           OR NEW.target_type IS NULL
           OR BINARY NEW.target_type <> BINARY 'service_subscription'
           OR NEW.target_id IS NULL
           OR NEW.request_fingerprint IS NULL
           OR CHAR_LENGTH(NEW.request_fingerprint) <> 64
           OR BINARY NEW.request_fingerprint <> BINARY COALESCE(@app_service_lifecycle_request_hash, '')
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
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service lifecycle caller audit shape is invalid.';
        END IF;

        SELECT COUNT(*) INTO valid_operation_count
        FROM provisioning_operations operation_row
        INNER JOIN service_subscriptions service_row
            ON service_row.id = operation_row.service_subscription_id
        WHERE BINARY operation_row.public_id = BINARY COALESCE(@app_service_lifecycle_operation_public_id, '')
          AND operation_row.operation_type <> 'initial_provision'
          AND operation_row.state = 'queued'
          AND BINARY operation_row.request_key_hash = BINARY NEW.request_fingerprint
          AND BINARY operation_row.correlation_id = BINARY NEW.correlation_id
          AND BINARY service_row.public_id = BINARY NEW.target_id
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_version')) AS UNSIGNED) = operation_row.target_lifecycle_version
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = operation_row.target_remote_identity_generation
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.mutation_generation')) AS UNSIGNED) + 1 = operation_row.operation_generation
          AND (
              (operation_row.operation_type = 'suspend'
                  AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_state')) = BINARY 'active')
              OR (operation_row.operation_type = 'activate'
                  AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_state')) = BINARY 'suspended')
              OR (operation_row.operation_type IN ('delete', 'reset_usage', 'rotate_subscription_link')
                  AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_state')) IN (BINARY 'active', BINARY 'suspended'))
          )
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.operation_public_id')) = BINARY operation_row.public_id
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.operation_type')) = BINARY operation_row.operation_type
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.operation_generation')) AS UNSIGNED) = operation_row.operation_generation
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.target_remote_identity_generation')) AS UNSIGNED) = operation_row.target_remote_identity_generation
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.target_lifecycle_version')) AS UNSIGNED) = operation_row.target_lifecycle_version
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.accepted_state')) = BINARY 'queued'
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

        IF valid_operation_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service lifecycle caller audit is detached from mutation authority.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER audit_logs_service_lifecycle_update_guard
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    IF BINARY OLD.action = BINARY 'service.lifecycle.command.accepted'
       OR BINARY NEW.action = BINARY 'service.lifecycle.command.accepted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service lifecycle caller audit is immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER audit_logs_service_lifecycle_delete_guard
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    IF BINARY OLD.action = BINARY 'service.lifecycle.command.accepted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service lifecycle caller audit is non-deletable.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('audit_logs')->where('action', 'service.lifecycle.command.accepted')->exists()) {
            throw new RuntimeException('Cannot roll back Service lifecycle command audit authority after caller audit evidence exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_lifecycle_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_lifecycle_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_lifecycle_insert_guard');
    }
};
