CREATE OR REPLACE TRIGGER service_reconfiguration_previews_insert_guard
BEFORE INSERT ON service_reconfiguration_previews
FOR EACH ROW
BEGIN
    DECLARE valid_context INT DEFAULT 0;
    DECLARE expected_source_offering BIGINT UNSIGNED DEFAULT NULL;
    DECLARE expected_source_profile BIGINT UNSIGNED DEFAULT NULL;
    DECLARE expected_price_difference BIGINT UNSIGNED DEFAULT 0;
    DECLARE expected_operation_fee BIGINT UNSIGNED DEFAULT 0;
    DECLARE expected_discount_eligible TINYINT DEFAULT 1;

    SELECT COALESCE(selection.plan_offering_id, item.plan_offering_id), selection.panel_protocol_profile_id
    INTO expected_source_offering, expected_source_profile
    FROM service_subscriptions service_row
    INNER JOIN order_items item ON item.id = service_row.order_item_id
    LEFT JOIN plan_offering_route_selections selection ON selection.id = service_row.route_selection_id
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.user_id = NEW.actor_user_id
      AND service_row.service_target_id = NEW.source_service_target_id
      AND service_row.remote_identity_generation = NEW.source_remote_identity_generation
      AND service_row.lifecycle_version = NEW.source_lifecycle_version
      AND service_row.mutation_generation = NEW.source_mutation_generation
      AND service_row.lifecycle_state IN ('active','suspended')
      AND service_row.remote_deleted_at IS NULL
      AND service_row.provisioned_at IS NOT NULL
      AND service_row.remote_service_id IS NOT NULL
      AND (service_row.route_selection_id <=> NEW.source_route_selection_id);

    SELECT COUNT(*) INTO valid_context
    FROM users user_row
    INNER JOIN plan_offerings source_offering ON source_offering.id = NEW.source_plan_offering_id
    INNER JOIN plan_offerings target_offering ON target_offering.id = NEW.target_plan_offering_id
    INNER JOIN plan_offering_route_selections target_selection ON target_selection.id = NEW.target_route_selection_id
    INNER JOIN panel_capacity_reservations reservation ON reservation.id = NEW.target_capacity_reservation_id
    INNER JOIN panel_target_capacities capacity ON capacity.id = reservation.panel_target_capacity_id
    INNER JOIN panel_service_targets source_target ON source_target.id = NEW.source_service_target_id
    INNER JOIN panel_service_targets target_row ON target_row.id = NEW.target_service_target_id
    INNER JOIN panel_protocol_profiles profile_row ON profile_row.id = NEW.target_protocol_profile_id
    WHERE user_row.id = NEW.actor_user_id
      AND user_row.account_status = 'active'
      AND user_row.account_type IN ('customer','agent')
      AND source_offering.id = expected_source_offering
      AND target_offering.state = 'active'
      AND target_selection.user_id = NEW.actor_user_id
      AND target_selection.plan_offering_id = NEW.target_plan_offering_id
      AND target_selection.selected_service_target_id = NEW.target_service_target_id
      AND target_selection.panel_protocol_profile_id = NEW.target_protocol_profile_id
      AND target_selection.capacity_reservation_id = NEW.target_capacity_reservation_id
      AND BINARY reservation.reservation_key = BINARY NEW.target_capacity_reservation_key
      AND reservation.state = 'held'
      AND reservation.units = 1
      AND reservation.expires_at = NEW.expires_at
      AND capacity.panel_service_target_id = NEW.target_service_target_id
      AND capacity.state = 'enabled'
      AND source_target.panel_connection_id = target_row.panel_connection_id
      AND EXISTS (
          SELECT 1
          FROM panel_target_capabilities capability_row
          WHERE capability_row.panel_service_target_id = source_target.id
            AND capability_row.capability_code = 'reconfigure_service'
            AND capability_row.verification_status = 'verified'
      )
      AND target_row.state = 'active'
      AND target_row.capability_status = 'verified'
      AND target_row.version = NEW.target_service_target_version
      AND profile_row.state = 'active'
      AND profile_row.version = NEW.target_protocol_profile_version;

    SET expected_price_difference = GREATEST(
        (SELECT base_price_irr FROM plan_offerings WHERE id = NEW.target_plan_offering_id)
        - (SELECT base_price_irr FROM plan_offerings WHERE id = NEW.source_plan_offering_id),
        0
    );

    SELECT COALESCE(SUM(operation_row.price_irr), 0),
           COALESCE(MIN(operation_row.discount_eligible), 1)
    INTO expected_operation_fee, expected_discount_eligible
    FROM plan_offering_operations operation_row
    WHERE operation_row.plan_offering_id = NEW.source_plan_offering_id
      AND (
          (NEW.actor_administrator_id IS NULL AND operation_row.customer_enabled = 1)
          OR (NEW.actor_administrator_id IS NOT NULL AND operation_row.administrator_enabled = 1)
      )
      AND operation_row.operation_code IN (
          IF(NEW.changes_plan = 1, 'change_plan', '__none__'),
          IF(NEW.changes_target = 1, 'change_location', '__none__'),
          IF(NEW.changes_protocol = 1, 'change_protocol', '__none__')
      );

    IF COALESCE(@app_service_reconfiguration_authority, '') <> 'service_reconfiguration_preview_v1'
       OR NOT EXISTS (
            SELECT 1 FROM service_operational_authority_capability capability_row
            WHERE capability_row.id = 1
              AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
       )
       OR valid_context <> 1
       OR expected_source_offering IS NULL
       OR NEW.source_plan_offering_id <> expected_source_offering
       OR NOT (NEW.source_protocol_profile_id <=> expected_source_profile)
       OR (NEW.source_route_selection_id IS NOT NULL AND NOT EXISTS (
            SELECT 1
            FROM plan_offering_route_selections source_selection
            INNER JOIN panel_capacity_reservations source_reservation ON source_reservation.id = source_selection.capacity_reservation_id
            WHERE source_selection.id = NEW.source_route_selection_id
              AND source_selection.selected_service_target_id = NEW.source_service_target_id
              AND source_reservation.state = 'committed'
              AND source_reservation.units = 1
       ))
       OR NEW.changes_plan <> (NEW.source_plan_offering_id <> NEW.target_plan_offering_id)
       OR NEW.changes_target <> (NEW.source_service_target_id <> NEW.target_service_target_id)
       OR NEW.changes_protocol <> (NOT (NEW.source_protocol_profile_id <=> NEW.target_protocol_profile_id))
       OR NEW.price_difference_irr <> expected_price_difference
       OR NEW.operation_fee_irr <> expected_operation_fee
       OR NEW.total_price_irr <> expected_price_difference + expected_operation_fee
       OR NEW.discount_eligible <> (
            expected_discount_eligible
            AND (SELECT discount_eligible FROM plan_offerings WHERE id = NEW.target_plan_offering_id)
       )
       OR (NEW.changes_plan = 1 AND NOT EXISTS (
            SELECT 1 FROM plan_offering_operations p
            WHERE p.plan_offering_id = NEW.source_plan_offering_id
              AND p.operation_code = 'change_plan'
              AND (
                  (NEW.actor_administrator_id IS NULL AND p.customer_enabled = 1)
                  OR (NEW.actor_administrator_id IS NOT NULL AND p.administrator_enabled = 1)
              )
              AND (p.required_capability_code IS NULL OR EXISTS (
                  SELECT 1 FROM panel_target_capabilities capability_row
                  WHERE capability_row.panel_service_target_id = NEW.source_service_target_id
                    AND BINARY capability_row.capability_code = BINARY p.required_capability_code
                    AND capability_row.verification_status = 'verified'
              ))
       ))
       OR (NEW.changes_target = 1 AND NOT EXISTS (
            SELECT 1 FROM plan_offering_operations p
            WHERE p.plan_offering_id = NEW.source_plan_offering_id
              AND p.operation_code = 'change_location'
              AND (
                  (NEW.actor_administrator_id IS NULL AND p.customer_enabled = 1)
                  OR (NEW.actor_administrator_id IS NOT NULL AND p.administrator_enabled = 1)
              )
              AND (p.required_capability_code IS NULL OR EXISTS (
                  SELECT 1 FROM panel_target_capabilities capability_row
                  WHERE capability_row.panel_service_target_id = NEW.source_service_target_id
                    AND BINARY capability_row.capability_code = BINARY p.required_capability_code
                    AND capability_row.verification_status = 'verified'
              ))
       ))
       OR (NEW.changes_protocol = 1 AND NOT EXISTS (
            SELECT 1 FROM plan_offering_operations p
            WHERE p.plan_offering_id = NEW.source_plan_offering_id
              AND p.operation_code = 'change_protocol'
              AND (
                  (NEW.actor_administrator_id IS NULL AND p.customer_enabled = 1)
                  OR (NEW.actor_administrator_id IS NOT NULL AND p.administrator_enabled = 1)
              )
              AND (p.required_capability_code IS NULL OR EXISTS (
                  SELECT 1 FROM panel_target_capabilities capability_row
                  WHERE capability_row.panel_service_target_id = NEW.source_service_target_id
                    AND BINARY capability_row.capability_code = BINARY p.required_capability_code
                    AND capability_row.verification_status = 'verified'
              ))
       ))
       OR (
            NEW.actor_administrator_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM administrators administrator_row
                WHERE administrator_row.id = NEW.actor_administrator_id
                  AND administrator_row.status = 'active'
            )
       )
       OR (NEW.actor_administrator_id IS NOT NULL AND NEW.total_price_irr <> 0)
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_service_reconfiguration_request_hash, '')
       OR BINARY NEW.payload_hash <> BINARY COALESCE(@app_service_reconfiguration_payload_hash, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_reconfiguration_correlation_id, '')
       OR NEW.state <> 'previewed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration preview authority is invalid.';
    END IF;
END
