<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PAY-003 PRV-002 PRV-003 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_update_guard
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE identity_unchanged BOOLEAN DEFAULT FALSE;
    DECLARE claim_transition BOOLEAN DEFAULT FALSE;
    DECLARE bind_transition BOOLEAN DEFAULT FALSE;
    DECLARE final_transition BOOLEAN DEFAULT FALSE;
    DECLARE recovery_transition BOOLEAN DEFAULT FALSE;

    SET identity_unchanged =
        NEW.id = OLD.id
        AND BINARY NEW.public_id = BINARY OLD.public_id
        AND BINARY NEW.operation_key = BINARY OLD.operation_key
        AND BINARY NEW.operation_type = BINARY OLD.operation_type
        AND NEW.order_id = OLD.order_id
        AND NEW.order_item_id = OLD.order_item_id
        AND NEW.service_subscription_id = OLD.service_subscription_id
        AND NEW.user_id = OLD.user_id
        AND BINARY NEW.correlation_id = BINARY OLD.correlation_id
        AND NEW.created_at = OLD.created_at;

    IF COALESCE(@app_provisioning_authority, '') <> 'initial_remote_effect_v1'
       OR COALESCE(@app_provisioning_operation_key, '') <> OLD.operation_key
       OR COALESCE(@app_provisioning_correlation_id, '') <> OLD.correlation_id
       OR COALESCE(identity_unchanged, FALSE) = FALSE THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect mutation authority is invalid.';
    END IF;

    SET claim_transition =
        OLD.state IN ('queued', 'retry_scheduled')
        AND NEW.state = 'running'
        AND NEW.state_version = OLD.state_version + 1
        AND NEW.attempt_count = OLD.attempt_count + 1
        AND NEW.effect_fence_key IS NOT NULL
        AND (OLD.effect_fence_key IS NULL OR BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key)
        AND NEW.route_hold_expires_at IS NOT NULL
        AND (OLD.route_selection_id IS NULL OR NEW.route_hold_expires_at = OLD.route_hold_expires_at)
        AND (NEW.route_selection_id <=> OLD.route_selection_id)
        AND (NEW.service_target_id <=> OLD.service_target_id)
        AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
        AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
        AND (NEW.remote_username <=> OLD.remote_username)
        AND (NEW.target_reference <=> OLD.target_reference)
        AND (NEW.last_result_code <=> OLD.last_result_code)
        AND (NEW.last_result_message <=> OLD.last_result_message)
        AND (NEW.remote_service_id <=> OLD.remote_service_id)
        AND NEW.remote_effect_started_at IS NOT NULL
        AND (OLD.remote_effect_started_at IS NULL OR NEW.remote_effect_started_at = OLD.remote_effect_started_at)
        AND (NEW.remote_effect_completed_at <=> OLD.remote_effect_completed_at);

    SET bind_transition =
        OLD.state = 'running'
        AND NEW.state = 'running'
        AND NEW.state_version = OLD.state_version
        AND NEW.attempt_count = OLD.attempt_count
        AND BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key
        AND NEW.route_hold_expires_at = OLD.route_hold_expires_at
        AND NEW.route_selection_id IS NOT NULL
        AND NEW.service_target_id IS NOT NULL
        AND NEW.capacity_reservation_id IS NOT NULL
        AND NEW.capacity_reservation_key IS NOT NULL
        AND NEW.remote_username IS NOT NULL
        AND NEW.target_reference IS NOT NULL
        AND (OLD.route_selection_id IS NULL OR NEW.route_selection_id = OLD.route_selection_id)
        AND (OLD.service_target_id IS NULL OR NEW.service_target_id = OLD.service_target_id)
        AND (OLD.capacity_reservation_id IS NULL OR NEW.capacity_reservation_id = OLD.capacity_reservation_id)
        AND (OLD.capacity_reservation_key IS NULL OR BINARY NEW.capacity_reservation_key = BINARY OLD.capacity_reservation_key)
        AND (OLD.remote_username IS NULL OR BINARY NEW.remote_username = BINARY OLD.remote_username)
        AND (OLD.target_reference IS NULL OR BINARY NEW.target_reference = BINARY OLD.target_reference)
        AND (NEW.last_result_code <=> OLD.last_result_code)
        AND (NEW.last_result_message <=> OLD.last_result_message)
        AND (NEW.remote_service_id <=> OLD.remote_service_id)
        AND NEW.remote_effect_started_at = OLD.remote_effect_started_at
        AND (NEW.remote_effect_completed_at <=> OLD.remote_effect_completed_at);

    SET final_transition =
        OLD.state = 'running'
        AND NEW.state IN ('succeeded', 'retry_scheduled', 'uncertain_remote_result', 'needs_review', 'failed_final')
        AND NEW.state_version = OLD.state_version + 1
        AND NEW.attempt_count = OLD.attempt_count
        AND BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key
        AND NEW.route_hold_expires_at = OLD.route_hold_expires_at
        AND (NEW.route_selection_id <=> OLD.route_selection_id)
        AND (NEW.service_target_id <=> OLD.service_target_id)
        AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
        AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
        AND (NEW.remote_username <=> OLD.remote_username)
        AND (NEW.target_reference <=> OLD.target_reference)
        AND NEW.last_result_code IS NOT NULL
        AND (OLD.remote_service_id IS NULL OR BINARY NEW.remote_service_id = BINARY OLD.remote_service_id)
        AND NEW.remote_effect_started_at = OLD.remote_effect_started_at
        AND NEW.remote_effect_completed_at IS NOT NULL
        AND (NEW.state <> 'succeeded'
             OR (NEW.route_selection_id IS NOT NULL
                 AND NEW.service_target_id IS NOT NULL
                 AND NEW.capacity_reservation_id IS NOT NULL
                 AND NEW.capacity_reservation_key IS NOT NULL
                 AND NEW.remote_username IS NOT NULL
                 AND NEW.target_reference IS NOT NULL
                 AND NEW.remote_service_id IS NOT NULL));

    SET recovery_transition =
        OLD.state = 'uncertain_remote_result'
        AND NEW.state = 'retry_scheduled'
        AND NEW.state_version = OLD.state_version + 1
        AND NEW.attempt_count = OLD.attempt_count
        AND (NEW.effect_fence_key <=> OLD.effect_fence_key)
        AND (NEW.route_hold_expires_at <=> OLD.route_hold_expires_at)
        AND (NEW.route_selection_id <=> OLD.route_selection_id)
        AND (NEW.service_target_id <=> OLD.service_target_id)
        AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
        AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
        AND (NEW.remote_username <=> OLD.remote_username)
        AND (NEW.target_reference <=> OLD.target_reference)
        AND (NEW.last_result_code <=> OLD.last_result_code)
        AND (NEW.last_result_message <=> OLD.last_result_message)
        AND (NEW.remote_service_id <=> OLD.remote_service_id)
        AND (NEW.remote_effect_started_at <=> OLD.remote_effect_started_at)
        AND (NEW.remote_effect_completed_at <=> OLD.remote_effect_completed_at);

    IF COALESCE(claim_transition, FALSE) = FALSE
       AND COALESCE(bind_transition, FALSE) = FALSE
       AND COALESCE(final_transition, FALSE) = FALSE
       AND COALESCE(recovery_transition, FALSE) = FALSE THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect transition is not allowed.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_remote_effect_events_insert_guard
BEFORE INSERT ON provisioning_remote_effect_events
FOR EACH ROW
BEGIN
    DECLARE valid_operation_count INT DEFAULT 0;

    IF COALESCE(@app_provisioning_authority, '') <> 'initial_remote_effect_v1' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_operation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.id = NEW.provisioning_operation_id
      AND BINARY operation_row.operation_key = BINARY COALESCE(@app_provisioning_operation_key, '')
      AND BINARY operation_row.correlation_id = BINARY COALESCE(@app_provisioning_correlation_id, '');

    IF valid_operation_count <> 1
       OR NEW.event_type NOT IN ('claimed', 'route_bound', 'succeeded', 'retry_scheduled', 'uncertain_remote_result', 'reconciliation_scheduled', 'needs_review', 'failed_final') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event is inconsistent.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        $previous = require __DIR__.'/2026_08_16_000101_harden_initial_provisioning_remote_effect_transitions.php';
        if (! is_object($previous) || ! method_exists($previous, 'up')) {
            throw new RuntimeException('Previous provisioning remote-effect transition migration is unavailable.');
        }
        $previous->up();

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_remote_effect_events_insert_guard
BEFORE INSERT ON provisioning_remote_effect_events
FOR EACH ROW
BEGIN
    DECLARE valid_operation_count INT DEFAULT 0;

    IF COALESCE(@app_provisioning_authority, '') <> 'initial_remote_effect_v1' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_operation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.id = NEW.provisioning_operation_id
      AND BINARY operation_row.operation_key = BINARY COALESCE(@app_provisioning_operation_key, '')
      AND BINARY operation_row.correlation_id = BINARY COALESCE(@app_provisioning_correlation_id, '');

    IF valid_operation_count <> 1
       OR NEW.event_type NOT IN ('claimed', 'route_bound', 'succeeded', 'retry_scheduled', 'uncertain_remote_result', 'needs_review', 'failed_final') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event is inconsistent.';
    END IF;
END
SQL);
    }
};
