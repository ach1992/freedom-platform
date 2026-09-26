CREATE OR REPLACE TRIGGER service_reconfiguration_authorities_insert_guard
BEFORE INSERT ON service_reconfiguration_authorities
FOR EACH ROW
BEGIN
    IF NEW.authorization_mode = 'paid_purchase' THEN
        IF COALESCE(@app_service_mutation_authority, '') <> 'service_paid_mutation_queue_v1'
       OR NEW.result_remote_service_id IS NOT NULL
       OR NEW.remote_result_snapshot_hash IS NOT NULL
       OR NEW.result_recorded_at IS NOT NULL
       OR NOT EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
           INNER JOIN orders order_row ON order_row.id = NEW.purchase_order_id
           INNER JOIN order_items item_row ON item_row.id = NEW.purchase_order_item_id AND item_row.order_id = order_row.id
           INNER JOIN purchase_settlements settlement_row ON settlement_row.id = NEW.purchase_settlement_id
           INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
           INNER JOIN quotes quote_row ON quote_row.id = NEW.source_quote_id
           INNER JOIN service_reconfiguration_previews preview_row ON preview_row.id = NEW.reconfiguration_preview_id
           INNER JOIN plan_offerings target_offering ON target_offering.id = NEW.target_plan_offering_id
           INNER JOIN plan_offering_route_selections target_selection ON target_selection.id = NEW.target_route_selection_id
           INNER JOIN panel_capacity_reservations target_reservation ON target_reservation.id = NEW.target_capacity_reservation_id
           INNER JOIN panel_service_targets source_target ON source_target.id = NEW.source_service_target_id
           INNER JOIN panel_service_targets target_target ON target_target.id = NEW.target_service_target_id
           INNER JOIN panel_protocol_profiles target_profile ON target_profile.id = NEW.target_protocol_profile_id
           LEFT JOIN plan_offering_route_selections source_selection ON source_selection.id = NEW.source_route_selection_id
           LEFT JOIN panel_capacity_reservations source_reservation ON source_reservation.id = source_selection.capacity_reservation_id
           WHERE operation_row.id = NEW.provisioning_operation_id
             AND operation_row.operation_type = 'reconfigure'
             AND operation_row.state = 'queued'
             AND operation_row.state_version = 1
             AND operation_row.service_subscription_id = NEW.service_subscription_id
             AND operation_row.order_id = order_row.id
             AND operation_row.order_item_id = item_row.id
             AND operation_row.operation_generation = service_row.mutation_generation
             AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
             AND operation_row.target_lifecycle_version = service_row.lifecycle_version
             AND operation_row.service_target_id = NEW.source_service_target_id
             AND BINARY operation_row.remote_service_id = BINARY service_row.remote_service_id
             AND order_row.purchase_settlement_id = settlement_row.id
             AND order_row.payment_intent_id = intent_row.id
             AND order_row.source_quote_id = quote_row.id
             AND order_row.state = 'paid'
             AND order_row.state_version = 1
             AND item_row.source_quote_id = quote_row.id
             AND settlement_row.payment_intent_id = intent_row.id
             AND settlement_row.source_quote_id = quote_row.id
             AND intent_row.purpose = 'purchase'
             AND intent_row.state = 'captured'
             AND intent_row.captured_at IS NOT NULL
             AND quote_row.action_snapshot = 'reconfigure'
             AND quote_row.plan_offering_id = NEW.target_plan_offering_id
             AND BINARY quote_row.offering_code_snapshot = BINARY NEW.target_plan_offering_code
             AND quote_row.offering_version = NEW.target_plan_offering_version
             AND quote_row.service_subscription_id = service_row.id
             AND quote_row.service_reconfiguration_preview_id = preview_row.id
             AND quote_row.service_reconfiguration_preview_id = NEW.reconfiguration_preview_id
             AND (quote_row.service_source_route_selection_id_snapshot <=> NEW.source_route_selection_id)
             AND quote_row.service_target_id_snapshot = NEW.source_service_target_id
             AND quote_row.service_remote_identity_generation_snapshot = NEW.quoted_remote_identity_generation
             AND quote_row.service_lifecycle_version_snapshot = NEW.quoted_lifecycle_version
             AND quote_row.service_mutation_generation_snapshot = NEW.quoted_mutation_generation
             AND quote_row.service_target_route_selection_id_snapshot = NEW.target_route_selection_id
             AND quote_row.service_target_service_target_id_snapshot = NEW.target_service_target_id
             AND quote_row.service_target_service_target_version_snapshot = NEW.target_service_target_version
             AND quote_row.service_target_protocol_profile_id_snapshot = NEW.target_protocol_profile_id
             AND quote_row.service_target_protocol_profile_version_snapshot = NEW.target_protocol_profile_version
             AND preview_row.service_subscription_id = service_row.id
             AND preview_row.target_plan_offering_id = NEW.target_plan_offering_id
             AND target_offering.state = 'active'
             AND target_offering.visibility = 'visible'
             AND target_offering.version = NEW.target_plan_offering_version
             AND BINARY target_offering.code = BINARY NEW.target_plan_offering_code
             AND preview_row.actor_user_id = service_row.user_id
             AND (preview_row.source_route_selection_id <=> NEW.source_route_selection_id)
             AND preview_row.source_service_target_id = NEW.source_service_target_id
             AND preview_row.source_remote_identity_generation = NEW.quoted_remote_identity_generation
             AND preview_row.source_lifecycle_version = NEW.quoted_lifecycle_version
             AND preview_row.source_mutation_generation = NEW.quoted_mutation_generation
             AND preview_row.target_route_selection_id = NEW.target_route_selection_id
             AND preview_row.target_service_target_id = NEW.target_service_target_id
             AND preview_row.target_service_target_version = NEW.target_service_target_version
             AND preview_row.target_protocol_profile_id = NEW.target_protocol_profile_id
             AND preview_row.target_protocol_profile_version = NEW.target_protocol_profile_version
             AND preview_row.target_capacity_reservation_id = NEW.target_capacity_reservation_id
             AND BINARY preview_row.target_capacity_reservation_key = BINARY NEW.target_capacity_reservation_key
             AND service_row.user_id = order_row.user_id
             AND (service_row.route_selection_id <=> NEW.source_route_selection_id)
             AND service_row.service_target_id = NEW.source_service_target_id
             AND service_row.remote_identity_generation = NEW.quoted_remote_identity_generation
             AND service_row.lifecycle_version = NEW.quoted_lifecycle_version
             AND service_row.mutation_generation = NEW.quoted_mutation_generation + 1
             AND service_row.lifecycle_state IN ('active','suspended')
             AND service_row.remote_deleted_at IS NULL
             AND target_selection.plan_offering_id = preview_row.target_plan_offering_id
             AND target_selection.selected_service_target_id = NEW.target_service_target_id
             AND target_selection.panel_protocol_profile_id = NEW.target_protocol_profile_id
             AND target_selection.capacity_reservation_id = NEW.target_capacity_reservation_id
             AND target_reservation.state = 'held'
             AND target_reservation.units = 1
             AND BINARY target_reservation.reservation_key = BINARY NEW.target_capacity_reservation_key
             AND source_target.panel_connection_id = target_target.panel_connection_id
             AND target_target.state = 'active'
             AND target_target.capability_status = 'verified'
             AND target_target.version = NEW.target_service_target_version
             AND target_profile.state = 'active'
             AND target_profile.version = NEW.target_protocol_profile_version
             AND (NEW.source_route_selection_id IS NULL OR (source_reservation.state = 'committed' AND source_reservation.units = 1))
             AND NOT EXISTS (
                 SELECT 1 FROM provisioning_financial_invalidations invalidation
                 WHERE invalidation.purchase_settlement_id = settlement_row.id
                   AND invalidation.payment_intent_id = intent_row.id
             )
       ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration authority does not match captured purchase, destination, and current Service authority.';
        END IF;
    ELSEIF NEW.authorization_mode = 'no_charge' THEN
        IF COALESCE(@app_service_mutation_authority, '') <> 'service_reconfiguration_no_charge_queue_v1'
           OR NEW.source_quote_id IS NOT NULL
           OR NEW.purchase_order_id IS NOT NULL
           OR NEW.purchase_order_item_id IS NOT NULL
           OR NEW.purchase_settlement_id IS NOT NULL
           OR NEW.payment_intent_id IS NOT NULL
           OR NEW.result_remote_service_id IS NOT NULL
           OR NEW.remote_result_snapshot_hash IS NOT NULL
           OR NEW.result_recorded_at IS NOT NULL
           OR NOT EXISTS (
               SELECT 1
               FROM provisioning_operations operation_row
               INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
               INNER JOIN service_reconfiguration_previews preview_row ON preview_row.id = NEW.reconfiguration_preview_id
               INNER JOIN plan_offerings target_offering ON target_offering.id = NEW.target_plan_offering_id
               INNER JOIN plan_offering_route_selections target_selection ON target_selection.id = NEW.target_route_selection_id
               INNER JOIN panel_capacity_reservations target_reservation ON target_reservation.id = NEW.target_capacity_reservation_id
               INNER JOIN panel_service_targets source_target ON source_target.id = NEW.source_service_target_id
               INNER JOIN panel_service_targets target_target ON target_target.id = NEW.target_service_target_id
               INNER JOIN panel_protocol_profiles target_profile ON target_profile.id = NEW.target_protocol_profile_id
               LEFT JOIN plan_offering_route_selections source_selection ON source_selection.id = NEW.source_route_selection_id
               LEFT JOIN panel_capacity_reservations source_reservation ON source_reservation.id = source_selection.capacity_reservation_id
               WHERE operation_row.id = NEW.provisioning_operation_id
                 AND operation_row.operation_type = 'reconfigure'
                 AND operation_row.state = 'queued'
                 AND operation_row.state_version = 1
                 AND operation_row.service_subscription_id = NEW.service_subscription_id
                 AND operation_row.order_id = service_row.order_id
                 AND operation_row.order_item_id = service_row.order_item_id
                 AND operation_row.user_id = service_row.user_id
                 AND operation_row.operation_generation = service_row.mutation_generation
                 AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
                 AND operation_row.target_lifecycle_version = service_row.lifecycle_version
                 AND operation_row.service_target_id = NEW.source_service_target_id
                 AND BINARY operation_row.remote_service_id = BINARY service_row.remote_service_id
                 AND BINARY operation_row.request_key_hash = BINARY COALESCE(@app_service_mutation_request_hash, '')
                 AND BINARY operation_row.correlation_id = BINARY COALESCE(@app_service_mutation_correlation_id, '')
                 AND preview_row.id = COALESCE(@app_service_reconfiguration_preview_id, 0)
                 AND preview_row.service_subscription_id = service_row.id
                 AND preview_row.actor_user_id = service_row.user_id
                 AND preview_row.total_price_irr = 0
                 AND preview_row.state = 'previewed'
                 AND preview_row.expires_at > CURRENT_TIMESTAMP(6)
                 AND preview_row.target_plan_offering_id = NEW.target_plan_offering_id
                 AND target_offering.state = 'active'
                 AND target_offering.visibility = 'visible'
                 AND target_offering.version = NEW.target_plan_offering_version
                 AND BINARY target_offering.code = BINARY NEW.target_plan_offering_code
                 AND (preview_row.source_route_selection_id <=> NEW.source_route_selection_id)
                 AND preview_row.source_service_target_id = NEW.source_service_target_id
                 AND preview_row.source_remote_identity_generation = NEW.quoted_remote_identity_generation
                 AND preview_row.source_lifecycle_version = NEW.quoted_lifecycle_version
                 AND preview_row.source_mutation_generation = NEW.quoted_mutation_generation
                 AND preview_row.target_route_selection_id = NEW.target_route_selection_id
                 AND preview_row.target_service_target_id = NEW.target_service_target_id
                 AND preview_row.target_service_target_version = NEW.target_service_target_version
                 AND preview_row.target_protocol_profile_id = NEW.target_protocol_profile_id
                 AND preview_row.target_protocol_profile_version = NEW.target_protocol_profile_version
                 AND preview_row.target_capacity_reservation_id = NEW.target_capacity_reservation_id
                 AND BINARY preview_row.target_capacity_reservation_key = BINARY NEW.target_capacity_reservation_key
                 AND (service_row.route_selection_id <=> NEW.source_route_selection_id)
                 AND service_row.service_target_id = NEW.source_service_target_id
                 AND service_row.remote_identity_generation = NEW.quoted_remote_identity_generation
                 AND service_row.lifecycle_version = NEW.quoted_lifecycle_version
                 AND service_row.mutation_generation = NEW.quoted_mutation_generation + 1
                 AND service_row.lifecycle_state IN ('active','suspended')
                 AND service_row.provisioned_at IS NOT NULL
                 AND service_row.remote_deleted_at IS NULL
                 AND target_selection.plan_offering_id = NEW.target_plan_offering_id
                 AND target_selection.selected_service_target_id = NEW.target_service_target_id
                 AND target_selection.panel_protocol_profile_id = NEW.target_protocol_profile_id
                 AND target_selection.capacity_reservation_id = NEW.target_capacity_reservation_id
                 AND target_reservation.state = 'held'
                 AND target_reservation.units = 1
                 AND target_reservation.expires_at > CURRENT_TIMESTAMP(6)
                 AND BINARY target_reservation.reservation_key = BINARY NEW.target_capacity_reservation_key
                 AND source_target.panel_connection_id = target_target.panel_connection_id
                 AND target_target.state = 'active'
                 AND target_target.capability_status = 'verified'
                 AND target_target.version = NEW.target_service_target_version
                 AND target_profile.state = 'active'
                 AND target_profile.version = NEW.target_protocol_profile_version
                 AND (NEW.source_route_selection_id IS NULL OR (source_reservation.state = 'committed' AND source_reservation.units = 1))
           ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zero-cost Service reconfiguration authority does not match preview, destination, and current Service authority.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration authorization mode is invalid.';
    END IF;
END
