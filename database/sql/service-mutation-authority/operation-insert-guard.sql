CREATE OR REPLACE TRIGGER provisioning_operations_insert_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_authority_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_mutation_service_id BIGINT UNSIGNED DEFAULT NULL;

    IF NEW.operation_type = 'initial_provision' THEN
        IF NEW.attempt_count <> 0
           OR NEW.effect_fence_key IS NOT NULL
           OR NEW.route_hold_expires_at IS NOT NULL
           OR NEW.route_selection_id IS NOT NULL
           OR NEW.service_target_id IS NOT NULL
           OR NEW.capacity_reservation_id IS NOT NULL
           OR NEW.capacity_reservation_key IS NOT NULL
           OR NEW.remote_username IS NOT NULL
           OR NEW.target_reference IS NOT NULL
           OR NEW.last_result_code IS NOT NULL
           OR NEW.last_result_message IS NOT NULL
           OR NEW.remote_service_id IS NOT NULL
           OR NEW.remote_effect_started_at IS NOT NULL
           OR NEW.remote_effect_completed_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation cannot be created with remote-effect evidence.';
        END IF;

        SELECT purchase_settlement_id, payment_intent_id INTO authority_settlement_id, authority_intent_id
        FROM orders WHERE id = NEW.order_id LIMIT 1;

        IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires matching captured purchase authority and Service identity.';
        END IF;

        SELECT id INTO locked_settlement_id FROM purchase_settlements WHERE id = authority_settlement_id LIMIT 1 FOR UPDATE;
        SELECT id INTO locked_intent_id FROM payment_intents WHERE id = authority_intent_id LIMIT 1 FOR UPDATE;

        SELECT order_row.id INTO valid_authority_order_id
        FROM orders order_row
        INNER JOIN order_items item_row ON item_row.id = NEW.order_item_id
        INNER JOIN service_subscriptions service_row ON service_row.id = NEW.service_subscription_id
        INNER JOIN purchase_settlements settlement_row ON settlement_row.id = order_row.purchase_settlement_id
        INNER JOIN payment_intents intent_row ON intent_row.id = order_row.payment_intent_id
        WHERE order_row.id = NEW.order_id
          AND settlement_row.id = locked_settlement_id
          AND intent_row.id = locked_intent_id
          AND item_row.order_id = order_row.id
          AND service_row.order_id = order_row.id
          AND service_row.order_item_id = item_row.id
          AND service_row.user_id = order_row.user_id
          AND NEW.user_id = order_row.user_id
          AND BINARY NEW.correlation_id = BINARY service_row.creation_correlation_id
          AND NEW.operation_generation = 0
          AND NEW.target_remote_identity_generation = 0
          AND NEW.target_lifecycle_version = 0
          AND NEW.request_key_hash IS NULL
          AND NEW.operation_key = CONCAT('initial-provision:', item_row.public_id)
          AND NEW.state = 'queued'
          AND NEW.state_version = 1
          AND order_row.source_type = 'purchase'
          AND order_row.state = 'paid'
          AND order_row.state_version = 1
          AND settlement_row.payment_intent_id = intent_row.id
          AND intent_row.purpose = 'purchase'
          AND intent_row.wallet_account_id IS NULL
          AND intent_row.state = 'captured'
          AND intent_row.captured_at IS NOT NULL
        LIMIT 1 FOR UPDATE;

        IF valid_authority_order_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires matching captured purchase authority and Service identity.';
        END IF;
    ELSE
        IF COALESCE(@app_service_mutation_authority, '') <> 'service_mutation_queue_v1'
           OR NEW.operation_type NOT IN ('reset_usage','suspend','activate','delete','rotate_subscription_link')
           OR NEW.operation_generation < 1
           OR NEW.operation_generation <> COALESCE(@app_service_mutation_generation, 0)
           OR NEW.target_remote_identity_generation < 1
           OR NEW.request_key_hash IS NULL
           OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_service_mutation_request_hash, '')
           OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_mutation_correlation_id, '')
           OR NEW.state <> 'queued'
           OR NEW.state_version <> 1
           OR NEW.attempt_count <> 0
           OR NEW.effect_fence_key IS NOT NULL
           OR NEW.route_hold_expires_at IS NOT NULL
           OR NEW.route_selection_id IS NOT NULL
           OR NEW.capacity_reservation_id IS NOT NULL
           OR NEW.capacity_reservation_key IS NOT NULL
           OR NEW.remote_username IS NOT NULL
           OR NEW.target_reference IS NOT NULL
           OR NEW.remote_effect_started_at IS NOT NULL
           OR NEW.remote_effect_completed_at IS NOT NULL
           OR NEW.last_result_code IS NOT NULL
           OR NEW.last_result_message IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation operation creation authority is invalid.';
        END IF;

        SELECT service_row.id INTO valid_mutation_service_id
        FROM service_subscriptions service_row
        INNER JOIN order_items item_row ON item_row.id = service_row.order_item_id
        WHERE service_row.id = NEW.service_subscription_id
          AND service_row.order_id = NEW.order_id
          AND item_row.id = NEW.order_item_id
          AND item_row.order_id = NEW.order_id
          AND service_row.user_id = NEW.user_id
          AND service_row.mutation_generation = NEW.operation_generation
          AND service_row.remote_deleted_at IS NULL
          AND service_row.lifecycle_state IN ('active','suspended')
          AND (NEW.operation_type <> 'suspend' OR service_row.lifecycle_state = 'active')
          AND (NEW.operation_type <> 'activate' OR service_row.lifecycle_state = 'suspended')
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.service_target_id IS NOT NULL
          AND service_row.remote_service_id IS NOT NULL
          AND NEW.service_target_id = service_row.service_target_id
          AND BINARY NEW.remote_service_id = BINARY service_row.remote_service_id
          AND NEW.target_remote_identity_generation = service_row.remote_identity_generation
          AND NEW.target_lifecycle_version = service_row.lifecycle_version
          AND BINARY NEW.operation_key = BINARY CONCAT('service-mutation:', service_row.public_id, ':', NEW.operation_generation, ':', NEW.operation_type)
        LIMIT 1 FOR UPDATE;

        IF valid_mutation_service_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation operation does not match current Service generation, lifecycle, and remote binding.';
        END IF;
    END IF;
END
