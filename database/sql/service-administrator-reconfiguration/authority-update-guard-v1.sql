CREATE OR REPLACE TRIGGER service_reconfiguration_authorities_update_guard
BEFORE UPDATE ON service_reconfiguration_authorities
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_reconfiguration_result_authority, '') <> 'service_reconfiguration_result_v1'
       OR OLD.provisioning_operation_id <> COALESCE(@app_service_reconfiguration_operation_id, 0)
       OR BINARY NEW.result_remote_service_id <> BINARY COALESCE(@app_service_reconfiguration_result_remote_id, '')
       OR BINARY NEW.remote_result_snapshot_hash <> BINARY COALESCE(@app_service_reconfiguration_result_snapshot_hash, '')
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR NEW.provisioning_operation_id <> OLD.provisioning_operation_id
       OR NEW.service_subscription_id <> OLD.service_subscription_id
       OR BINARY NEW.authorization_mode <> BINARY OLD.authorization_mode
       OR NOT (NEW.source_quote_id <=> OLD.source_quote_id)
       OR NOT (NEW.purchase_order_id <=> OLD.purchase_order_id)
       OR NOT (NEW.purchase_order_item_id <=> OLD.purchase_order_item_id)
       OR NOT (NEW.purchase_settlement_id <=> OLD.purchase_settlement_id)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NEW.reconfiguration_preview_id <> OLD.reconfiguration_preview_id
       OR NEW.target_plan_offering_id <> OLD.target_plan_offering_id
       OR BINARY NEW.target_plan_offering_code <> BINARY OLD.target_plan_offering_code
       OR NEW.target_plan_offering_version <> OLD.target_plan_offering_version
       OR NOT (NEW.source_route_selection_id <=> OLD.source_route_selection_id)
       OR NEW.source_service_target_id <> OLD.source_service_target_id
       OR NEW.target_route_selection_id <> OLD.target_route_selection_id
       OR NEW.target_service_target_id <> OLD.target_service_target_id
       OR NEW.target_service_target_version <> OLD.target_service_target_version
       OR NEW.target_protocol_profile_id <> OLD.target_protocol_profile_id
       OR NEW.target_protocol_profile_version <> OLD.target_protocol_profile_version
       OR NEW.target_capacity_reservation_id <> OLD.target_capacity_reservation_id
       OR BINARY NEW.target_capacity_reservation_key <> BINARY OLD.target_capacity_reservation_key
       OR BINARY NEW.target_reference <> BINARY OLD.target_reference
       OR BINARY NEW.target_protocol_profile_code <> BINARY OLD.target_protocol_profile_code
       OR NEW.quoted_remote_identity_generation <> OLD.quoted_remote_identity_generation
       OR NEW.quoted_lifecycle_version <> OLD.quoted_lifecycle_version
       OR NEW.quoted_mutation_generation <> OLD.quoted_mutation_generation
       OR NEW.created_at <> OLD.created_at
       OR OLD.result_recorded_at IS NOT NULL
       OR OLD.result_remote_service_id IS NOT NULL
       OR OLD.remote_result_snapshot_hash IS NOT NULL
       OR NEW.result_recorded_at IS NULL
       OR NEW.result_remote_service_id IS NULL
       OR NEW.remote_result_snapshot_hash IS NULL
       OR NOT EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
           INNER JOIN panel_capacity_reservations target_reservation ON target_reservation.id = OLD.target_capacity_reservation_id
           INNER JOIN plan_offerings target_offering ON target_offering.id = OLD.target_plan_offering_id
           INNER JOIN panel_service_targets target_target ON target_target.id = OLD.target_service_target_id
           INNER JOIN panel_protocol_profiles target_profile ON target_profile.id = OLD.target_protocol_profile_id
           WHERE operation_row.id = OLD.provisioning_operation_id
             AND operation_row.service_subscription_id = OLD.service_subscription_id
             AND operation_row.operation_type = 'reconfigure'
             AND operation_row.state = 'running'
             AND operation_row.remote_effect_started_at IS NOT NULL
             AND operation_row.remote_effect_completed_at IS NULL
             AND operation_row.operation_generation = service_row.mutation_generation
             AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
             AND operation_row.target_lifecycle_version = service_row.lifecycle_version
             AND service_row.service_target_id = OLD.source_service_target_id
             AND (service_row.route_selection_id <=> OLD.source_route_selection_id)
             AND service_row.remote_deleted_at IS NULL
             AND service_row.lifecycle_state IN ('active','suspended')
             AND target_reservation.state = 'held'
             AND target_reservation.units = 1
             AND target_offering.state = 'active'
             AND target_offering.visibility = 'visible'
             AND target_offering.version = OLD.target_plan_offering_version
             AND BINARY target_offering.code = BINARY OLD.target_plan_offering_code
             AND target_target.state = 'active'
             AND target_target.capability_status = 'verified'
             AND target_target.version = OLD.target_service_target_version
             AND target_profile.state = 'active'
             AND target_profile.version = OLD.target_protocol_profile_version
             AND NOT EXISTS (
                 SELECT 1 FROM provisioning_financial_invalidations invalidation
                 WHERE invalidation.purchase_settlement_id = OLD.purchase_settlement_id
                   AND invalidation.payment_intent_id = OLD.payment_intent_id
             )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration remote result evidence authority is invalid.';
    END IF;
END
