CREATE OR REPLACE TRIGGER provisioning_operations_update_guard
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE authoritative_service_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE identity_unchanged BOOLEAN DEFAULT FALSE;
    DECLARE claim_transition BOOLEAN DEFAULT FALSE;
    DECLARE boundary_transition BOOLEAN DEFAULT FALSE;
    DECLARE stale_rejection_transition BOOLEAN DEFAULT FALSE;
    DECLARE bind_transition BOOLEAN DEFAULT FALSE;
    DECLARE final_transition BOOLEAN DEFAULT FALSE;
    DECLARE recovery_transition BOOLEAN DEFAULT FALSE;

    SET identity_unchanged =
        NEW.id = OLD.id
        AND BINARY NEW.public_id = BINARY OLD.public_id
        AND BINARY NEW.operation_key = BINARY OLD.operation_key
        AND BINARY NEW.operation_type = BINARY OLD.operation_type
        AND NEW.operation_generation = OLD.operation_generation
        AND NEW.target_remote_identity_generation = OLD.target_remote_identity_generation
        AND NEW.target_lifecycle_version = OLD.target_lifecycle_version
        AND (NEW.request_key_hash <=> OLD.request_key_hash)
        AND NEW.order_id = OLD.order_id
        AND NEW.order_item_id = OLD.order_item_id
        AND NEW.service_subscription_id = OLD.service_subscription_id
        AND NEW.user_id = OLD.user_id
        AND BINARY NEW.correlation_id = BINARY OLD.correlation_id
        AND NEW.created_at = OLD.created_at;

    IF COALESCE(identity_unchanged, FALSE) = FALSE
       OR COALESCE(@app_provisioning_operation_key, '') <> OLD.operation_key
       OR COALESCE(@app_provisioning_correlation_id, '') <> OLD.correlation_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect mutation authority is invalid.';
    END IF;

    IF OLD.operation_type = 'initial_provision' THEN
        IF COALESCE(@app_provisioning_authority, '') <> 'initial_remote_effect_v1'
           OR OLD.operation_generation <> 0
           OR OLD.target_remote_identity_generation <> 0
           OR OLD.target_lifecycle_version <> 0
           OR OLD.request_key_hash IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning remote-effect mutation authority is invalid.';
        END IF;

        SET claim_transition =
            OLD.state IN ('queued', 'retry_scheduled') AND NEW.state = 'running'
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
            OLD.state = 'running' AND NEW.state = 'running'
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
            AND NEW.state IN ('succeeded','retry_scheduled','uncertain_remote_result','needs_review','failed_final')
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
                 OR (NEW.route_selection_id IS NOT NULL AND NEW.service_target_id IS NOT NULL
                     AND NEW.capacity_reservation_id IS NOT NULL AND NEW.capacity_reservation_key IS NOT NULL
                     AND NEW.remote_username IS NOT NULL AND NEW.target_reference IS NOT NULL
                     AND NEW.remote_service_id IS NOT NULL));

        SET recovery_transition =
            OLD.state = 'uncertain_remote_result' AND NEW.state = 'retry_scheduled'
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
    ELSE
        IF COALESCE(@app_provisioning_authority, '') <> 'service_mutation_effect_v1'
           OR OLD.operation_type NOT IN ('reset_usage','suspend','activate','delete','rotate_subscription_link')
           OR OLD.operation_generation < 1
           OR OLD.operation_generation <> COALESCE(@app_service_mutation_generation, 0)
           OR OLD.target_remote_identity_generation < 1
           OR OLD.request_key_hash IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation remote-effect authority is invalid.';
        END IF;

        SELECT service_row.id INTO authoritative_service_id
        FROM service_subscriptions service_row
        WHERE service_row.id = OLD.service_subscription_id
          AND service_row.mutation_generation = OLD.operation_generation
          AND service_row.remote_identity_generation = OLD.target_remote_identity_generation
          AND service_row.lifecycle_version = OLD.target_lifecycle_version
          AND service_row.lifecycle_state IN ('active','suspended')
          AND service_row.remote_deleted_at IS NULL
          AND service_row.service_target_id = OLD.service_target_id
          AND BINARY service_row.remote_service_id = BINARY OLD.remote_service_id
          AND (OLD.operation_type <> 'suspend' OR service_row.lifecycle_state = 'active')
          AND (OLD.operation_type <> 'activate' OR service_row.lifecycle_state = 'suspended')
        LIMIT 1 FOR UPDATE;

        SET stale_rejection_transition =
            authoritative_service_id IS NULL
            AND OLD.state IN ('queued','retry_scheduled') AND NEW.state = 'needs_review'
            AND NEW.state_version = OLD.state_version + 1
            AND NEW.attempt_count = OLD.attempt_count
            AND (NEW.effect_fence_key <=> OLD.effect_fence_key)
            AND (NEW.route_hold_expires_at <=> OLD.route_hold_expires_at)
            AND (NEW.route_selection_id <=> OLD.route_selection_id)
            AND NEW.service_target_id = OLD.service_target_id
            AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
            AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
            AND (NEW.remote_username <=> OLD.remote_username)
            AND (NEW.target_reference <=> OLD.target_reference)
            AND NEW.last_result_code IS NOT NULL
            AND NEW.last_result_message IS NOT NULL
            AND BINARY NEW.remote_service_id = BINARY OLD.remote_service_id
            AND OLD.remote_effect_started_at IS NULL
            AND NEW.remote_effect_started_at IS NULL
            AND OLD.remote_effect_completed_at IS NULL
            AND NEW.remote_effect_completed_at IS NULL;

        SET claim_transition =
            authoritative_service_id IS NOT NULL
            AND OLD.state IN ('queued','retry_scheduled') AND NEW.state = 'running'
            AND NEW.state_version = OLD.state_version + 1
            AND NEW.attempt_count = OLD.attempt_count + 1
            AND NEW.effect_fence_key IS NOT NULL
            AND (OLD.effect_fence_key IS NULL OR BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key)
            AND (NEW.route_hold_expires_at <=> OLD.route_hold_expires_at)
            AND (NEW.route_selection_id <=> OLD.route_selection_id)
            AND NEW.service_target_id = OLD.service_target_id
            AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
            AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
            AND (NEW.remote_username <=> OLD.remote_username)
            AND (NEW.target_reference <=> OLD.target_reference)
            AND (NEW.last_result_code <=> OLD.last_result_code)
            AND (NEW.last_result_message <=> OLD.last_result_message)
            AND BINARY NEW.remote_service_id = BINARY OLD.remote_service_id
            AND OLD.remote_effect_started_at IS NULL
            AND NEW.remote_effect_started_at IS NULL
            AND OLD.remote_effect_completed_at IS NULL
            AND NEW.remote_effect_completed_at IS NULL;

        SET boundary_transition =
            authoritative_service_id IS NOT NULL
            AND OLD.state = 'running' AND NEW.state = 'running'
            AND NEW.state_version = OLD.state_version + 1
            AND NEW.attempt_count = OLD.attempt_count
            AND BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key
            AND (NEW.route_hold_expires_at <=> OLD.route_hold_expires_at)
            AND (NEW.route_selection_id <=> OLD.route_selection_id)
            AND NEW.service_target_id = OLD.service_target_id
            AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
            AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
            AND (NEW.remote_username <=> OLD.remote_username)
            AND (NEW.target_reference <=> OLD.target_reference)
            AND (NEW.last_result_code <=> OLD.last_result_code)
            AND (NEW.last_result_message <=> OLD.last_result_message)
            AND BINARY NEW.remote_service_id = BINARY OLD.remote_service_id
            AND OLD.remote_effect_started_at IS NULL
            AND NEW.remote_effect_started_at IS NOT NULL
            AND OLD.remote_effect_completed_at IS NULL
            AND NEW.remote_effect_completed_at IS NULL;

        SET final_transition =
            OLD.state = 'running'
            AND NEW.state IN ('succeeded','retry_scheduled','uncertain_remote_result','needs_review','failed_final')
            AND (NEW.state <> 'succeeded' OR authoritative_service_id IS NOT NULL)
            AND NEW.state_version = OLD.state_version + 1
            AND NEW.attempt_count = OLD.attempt_count
            AND BINARY NEW.effect_fence_key = BINARY OLD.effect_fence_key
            AND (NEW.route_hold_expires_at <=> OLD.route_hold_expires_at)
            AND (NEW.route_selection_id <=> OLD.route_selection_id)
            AND NEW.service_target_id = OLD.service_target_id
            AND (NEW.capacity_reservation_id <=> OLD.capacity_reservation_id)
            AND (NEW.capacity_reservation_key <=> OLD.capacity_reservation_key)
            AND (NEW.remote_username <=> OLD.remote_username)
            AND (NEW.target_reference <=> OLD.target_reference)
            AND NEW.last_result_code IS NOT NULL
            AND BINARY NEW.remote_service_id = BINARY OLD.remote_service_id
            AND (
                (OLD.remote_effect_started_at IS NULL
                 AND NEW.remote_effect_started_at IS NULL
                 AND NEW.remote_effect_completed_at IS NULL
                 AND NEW.state IN ('retry_scheduled','needs_review','failed_final'))
                OR
                (OLD.remote_effect_started_at IS NOT NULL
                 AND NEW.remote_effect_started_at = OLD.remote_effect_started_at
                 AND NEW.remote_effect_completed_at IS NOT NULL
                 AND NEW.state IN ('succeeded','uncertain_remote_result','needs_review','failed_final'))
            );
    END IF;

    IF COALESCE(claim_transition, FALSE) = FALSE
       AND COALESCE(boundary_transition, FALSE) = FALSE
       AND COALESCE(stale_rejection_transition, FALSE) = FALSE
       AND COALESCE(bind_transition, FALSE) = FALSE
       AND COALESCE(final_transition, FALSE) = FALSE
       AND COALESCE(recovery_transition, FALSE) = FALSE THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect transition is not allowed.';
    END IF;
END
