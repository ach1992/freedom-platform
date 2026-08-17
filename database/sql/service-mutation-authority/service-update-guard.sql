CREATE OR REPLACE TRIGGER service_subscriptions_update_guard
BEFORE UPDATE ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE unresolved_mutations INT DEFAULT 0;
    DECLARE effect_operation_count INT DEFAULT 0;
    DECLARE effect_operation_type VARCHAR(64) DEFAULT NULL;
    DECLARE identity_unchanged BOOLEAN DEFAULT FALSE;
    DECLARE remote_binding_unchanged BOOLEAN DEFAULT FALSE;

    SET identity_unchanged =
        NEW.id = OLD.id
        AND BINARY NEW.public_id = BINARY OLD.public_id
        AND NEW.order_id = OLD.order_id
        AND NEW.order_item_id = OLD.order_item_id
        AND NEW.user_id = OLD.user_id
        AND BINARY NEW.creation_correlation_id = BINARY OLD.creation_correlation_id
        AND NEW.created_at = OLD.created_at;

    SET remote_binding_unchanged =
        (NEW.route_selection_id <=> OLD.route_selection_id)
        AND (NEW.service_target_id <=> OLD.service_target_id)
        AND (NEW.remote_service_id <=> OLD.remote_service_id)
        AND (NEW.provisioned_at <=> OLD.provisioned_at);

    IF COALESCE(@app_provisioning_authority, '') = 'initial_remote_effect_v1' THEN
        IF COALESCE(@app_provisioning_operation_key, '') = ''
           OR COALESCE(@app_provisioning_correlation_id, '') <> OLD.creation_correlation_id
           OR COALESCE(identity_unchanged, FALSE) = FALSE
           OR NEW.route_selection_id IS NULL
           OR NEW.service_target_id IS NULL
           OR NEW.remote_service_id IS NULL
           OR NEW.provisioned_at IS NULL
           OR NEW.lifecycle_state <> OLD.lifecycle_state
           OR NEW.lifecycle_version <> OLD.lifecycle_version
           OR NEW.remote_identity_generation <> OLD.remote_identity_generation
           OR NEW.mutation_generation <> OLD.mutation_generation
           OR NOT (NEW.remote_deleted_at <=> OLD.remote_deleted_at)
           OR (OLD.route_selection_id IS NOT NULL AND NEW.route_selection_id <> OLD.route_selection_id)
           OR (OLD.service_target_id IS NOT NULL AND NEW.service_target_id <> OLD.service_target_id)
           OR (OLD.remote_service_id IS NOT NULL AND BINARY NEW.remote_service_id <> BINARY OLD.remote_service_id)
           OR (OLD.provisioned_at IS NOT NULL AND NEW.provisioned_at <> OLD.provisioned_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription remote binding mutation is not allowed.';
        END IF;
    ELSEIF COALESCE(@app_service_mutation_authority, '') = 'service_mutation_queue_v1' THEN
        SELECT COUNT(*) INTO unresolved_mutations
        FROM provisioning_operations operation_row
        WHERE operation_row.service_subscription_id = OLD.id
          AND operation_row.operation_type <> 'initial_provision'
          AND operation_row.state NOT IN ('succeeded','failed_final','compensated');

        IF COALESCE(identity_unchanged, FALSE) = FALSE
           OR COALESCE(remote_binding_unchanged, FALSE) = FALSE
           OR NEW.lifecycle_state <> OLD.lifecycle_state
           OR NEW.lifecycle_version <> OLD.lifecycle_version
           OR NEW.remote_identity_generation <> OLD.remote_identity_generation
           OR NOT (NEW.remote_deleted_at <=> OLD.remote_deleted_at)
           OR OLD.remote_deleted_at IS NOT NULL
           OR OLD.lifecycle_state = 'retired'
           OR NEW.mutation_generation <> OLD.mutation_generation + 1
           OR NEW.mutation_generation <> COALESCE(@app_service_mutation_generation, 0)
           OR COALESCE(@app_service_mutation_request_hash, '') = ''
           OR COALESCE(@app_service_mutation_correlation_id, '') = ''
           OR unresolved_mutations <> 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation generation transition is not allowed.';
        END IF;
    ELSEIF COALESCE(@app_provisioning_authority, '') = 'service_mutation_effect_v1' THEN
        SELECT COUNT(*), MAX(operation_row.operation_type)
        INTO effect_operation_count, effect_operation_type
        FROM provisioning_operations operation_row
        WHERE operation_row.service_subscription_id = OLD.id
          AND operation_row.operation_generation = OLD.mutation_generation
          AND operation_row.target_remote_identity_generation = OLD.remote_identity_generation
          AND operation_row.target_lifecycle_version = OLD.lifecycle_version
          AND operation_row.state = 'running'
          AND operation_row.service_target_id = OLD.service_target_id
          AND BINARY operation_row.remote_service_id = BINARY OLD.remote_service_id
          AND BINARY operation_row.operation_key = BINARY COALESCE(@app_provisioning_operation_key, '')
          AND BINARY operation_row.correlation_id = BINARY COALESCE(@app_provisioning_correlation_id, '');

        IF COALESCE(identity_unchanged, FALSE) = FALSE
           OR COALESCE(remote_binding_unchanged, FALSE) = FALSE
           OR NEW.remote_identity_generation <> OLD.remote_identity_generation
           OR NEW.mutation_generation <> OLD.mutation_generation
           OR NEW.mutation_generation <> COALESCE(@app_service_mutation_generation, 0)
           OR effect_operation_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation lifecycle authority is invalid.';
        END IF;

        IF effect_operation_type = 'suspend' THEN
            IF OLD.lifecycle_state <> 'active'
               OR NEW.lifecycle_state <> 'suspended'
               OR NEW.lifecycle_version <> OLD.lifecycle_version + 1
               OR OLD.remote_deleted_at IS NOT NULL
               OR NEW.remote_deleted_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service suspend lifecycle transition is not allowed.';
            END IF;
        ELSEIF effect_operation_type = 'activate' THEN
            IF OLD.lifecycle_state <> 'suspended'
               OR NEW.lifecycle_state <> 'active'
               OR NEW.lifecycle_version <> OLD.lifecycle_version + 1
               OR OLD.remote_deleted_at IS NOT NULL
               OR NEW.remote_deleted_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service activate lifecycle transition is not allowed.';
            END IF;
        ELSEIF effect_operation_type = 'delete' THEN
            IF OLD.lifecycle_state NOT IN ('active','suspended')
               OR NEW.lifecycle_state <> 'retired'
               OR NEW.lifecycle_version <> OLD.lifecycle_version + 1
               OR OLD.remote_deleted_at IS NOT NULL
               OR NEW.remote_deleted_at IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delete retirement transition is not allowed.';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This Service mutation cannot change local lifecycle state.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription mutation authority is invalid.';
    END IF;
END
