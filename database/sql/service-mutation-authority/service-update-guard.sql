CREATE OR REPLACE TRIGGER service_subscriptions_update_guard
BEFORE UPDATE ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE unresolved_mutations INT DEFAULT 0;
    DECLARE delete_operation_count INT DEFAULT 0;
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
           OR NOT (NEW.remote_deleted_at <=> OLD.remote_deleted_at)
           OR OLD.remote_deleted_at IS NOT NULL
           OR NEW.mutation_generation <> OLD.mutation_generation + 1
           OR NEW.mutation_generation <> COALESCE(@app_service_mutation_generation, 0)
           OR COALESCE(@app_service_mutation_request_hash, '') = ''
           OR COALESCE(@app_service_mutation_correlation_id, '') = ''
           OR unresolved_mutations <> 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation generation transition is not allowed.';
        END IF;
    ELSEIF COALESCE(@app_provisioning_authority, '') = 'service_mutation_effect_v1' THEN
        SELECT COUNT(*) INTO delete_operation_count
        FROM provisioning_operations operation_row
        WHERE operation_row.service_subscription_id = OLD.id
          AND operation_row.operation_type = 'delete'
          AND operation_row.operation_generation = OLD.mutation_generation
          AND operation_row.state = 'running'
          AND BINARY operation_row.operation_key = BINARY COALESCE(@app_provisioning_operation_key, '')
          AND BINARY operation_row.correlation_id = BINARY COALESCE(@app_provisioning_correlation_id, '');

        IF COALESCE(identity_unchanged, FALSE) = FALSE
           OR COALESCE(remote_binding_unchanged, FALSE) = FALSE
           OR NEW.mutation_generation <> OLD.mutation_generation
           OR NEW.mutation_generation <> COALESCE(@app_service_mutation_generation, 0)
           OR OLD.remote_deleted_at IS NOT NULL
           OR NEW.remote_deleted_at IS NULL
           OR delete_operation_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service remote-delete tombstone transition is not allowed.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription mutation authority is invalid.';
    END IF;
END
