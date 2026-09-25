CREATE TRIGGER quotes_insert_guard
BEFORE INSERT ON quotes
FOR EACH ROW
BEGIN
    DECLARE valid_user_count INT DEFAULT 0;
    DECLARE valid_offering_count INT DEFAULT 0;
    DECLARE valid_override_count INT DEFAULT 0;
    DECLARE valid_service_package_count INT DEFAULT 0;
    DECLARE valid_reconfiguration_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_user_count
    FROM users u
    WHERE u.id = NEW.user_id
      AND u.account_status = 'active'
      AND u.account_type = NEW.account_type_snapshot;
    IF valid_user_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote requires one active matching pricing subject.';
    END IF;

    SELECT COUNT(*) INTO valid_offering_count
    FROM plan_offerings o
    INNER JOIN plan_offering_histories h
        ON h.plan_offering_id = o.id
       AND h.version = NEW.offering_version
       AND h.to_configuration_hash = NEW.offering_configuration_hash
    WHERE o.id = NEW.plan_offering_id
      AND o.code = NEW.offering_code_snapshot
      AND o.version = NEW.offering_version
      AND (NEW.action_snapshot <> 'purchase' OR o.base_price_irr = NEW.base_price_irr)
      AND (NEW.action_snapshot <> 'purchase' OR o.discount_eligible = NEW.offering_discount_eligible);
    IF valid_offering_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote offering snapshot is not current.';
    END IF;

    IF NEW.action_snapshot IN ('renew','add_data','add_days','add_data_days') THEN
        SELECT COUNT(*) INTO valid_service_package_count
        FROM service_subscriptions service_row
        INNER JOIN order_items original_item ON original_item.id = service_row.order_item_id
        INNER JOIN plan_offering_packages package_row
            ON package_row.id = NEW.service_package_id_snapshot
           AND package_row.plan_offering_id = NEW.plan_offering_id
           AND package_row.code = NEW.service_package_code_snapshot
           AND package_row.package_type = NEW.service_package_type_snapshot
           AND package_row.price_irr = NEW.base_price_irr
           AND (package_row.duration_days <=> NEW.service_package_duration_days_snapshot)
           AND (package_row.data_bytes <=> NEW.service_package_data_bytes_snapshot)
        INNER JOIN plan_offering_operations operation_row
            ON operation_row.plan_offering_id = NEW.plan_offering_id
           AND operation_row.operation_code = NEW.action_snapshot
           AND operation_row.customer_enabled = 1
           AND (operation_row.required_capability_code <=> NEW.service_required_capability_code_snapshot)
        WHERE service_row.id = NEW.service_subscription_id
          AND service_row.public_id = NEW.service_subscription_public_id
          AND service_row.user_id = NEW.user_id
          AND original_item.plan_offering_id = NEW.plan_offering_id
          AND service_row.lifecycle_state IN ('active','suspended')
          AND service_row.remote_deleted_at IS NULL
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.service_target_id = NEW.service_target_id_snapshot
          AND service_row.remote_identity_generation = NEW.service_remote_identity_generation_snapshot
          AND service_row.lifecycle_version = NEW.service_lifecycle_version_snapshot
          AND service_row.remote_service_id IS NOT NULL
          AND NEW.offering_discount_eligible = (
              (SELECT offering_row.discount_eligible FROM plan_offerings offering_row WHERE offering_row.id = NEW.plan_offering_id)
              AND package_row.discount_eligible
              AND operation_row.discount_eligible
          )
          AND (
              (NEW.action_snapshot IN ('renew','add_days') AND EXISTS (
                  SELECT 1 FROM panel_target_capabilities capability
                  WHERE capability.panel_service_target_id = service_row.service_target_id
                    AND capability.capability_code = 'update_expiry'
                    AND capability.verification_status = 'verified'
              ))
              OR (NEW.action_snapshot = 'add_data' AND EXISTS (
                  SELECT 1 FROM panel_target_capabilities capability
                  WHERE capability.panel_service_target_id = service_row.service_target_id
                    AND capability.capability_code = 'add_data_allowance'
                    AND capability.verification_status = 'verified'
              ))
              OR (NEW.action_snapshot = 'add_data_days'
                  AND EXISTS (
                      SELECT 1 FROM panel_target_capabilities capability
                      WHERE capability.panel_service_target_id = service_row.service_target_id
                        AND capability.capability_code = 'update_expiry'
                        AND capability.verification_status = 'verified'
                  )
                  AND EXISTS (
                      SELECT 1 FROM panel_target_capabilities capability
                      WHERE capability.panel_service_target_id = service_row.service_target_id
                        AND capability.capability_code = 'add_data_allowance'
                        AND capability.verification_status = 'verified'
                  )
                  AND EXISTS (
                      SELECT 1 FROM panel_target_capabilities capability
                      WHERE capability.panel_service_target_id = service_row.service_target_id
                        AND capability.capability_code = 'atomic_service_entitlements'
                        AND capability.verification_status = 'verified'
                  ))
          )
          AND (operation_row.required_capability_code IS NULL OR EXISTS (
              SELECT 1 FROM panel_target_capabilities policy_capability
              WHERE policy_capability.panel_service_target_id = service_row.service_target_id
                AND policy_capability.capability_code = operation_row.required_capability_code
                AND policy_capability.verification_status = 'verified'
          ));
        IF valid_service_package_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service package Quote authority is stale or invalid.';
        END IF;
    END IF;

    IF NEW.action_snapshot = 'reconfigure' THEN
        SELECT COUNT(*) INTO valid_reconfiguration_count
        FROM service_reconfiguration_previews preview_row
        INNER JOIN service_subscriptions service_row ON service_row.id = preview_row.service_subscription_id
        INNER JOIN plan_offerings target_offering ON target_offering.id = preview_row.target_plan_offering_id
        INNER JOIN plan_offering_route_selections target_selection ON target_selection.id = preview_row.target_route_selection_id
        INNER JOIN panel_capacity_reservations reservation_row ON reservation_row.id = preview_row.target_capacity_reservation_id
        WHERE preview_row.id = NEW.service_reconfiguration_preview_id
          AND BINARY preview_row.public_id = BINARY NEW.service_reconfiguration_preview_public_id
          AND preview_row.actor_user_id = NEW.user_id
          AND preview_row.service_subscription_id = NEW.service_subscription_id
          AND preview_row.source_service_target_id = NEW.service_target_id_snapshot
          AND preview_row.source_remote_identity_generation = NEW.service_remote_identity_generation_snapshot
          AND preview_row.source_lifecycle_version = NEW.service_lifecycle_version_snapshot
          AND preview_row.source_mutation_generation = NEW.service_mutation_generation_snapshot
          AND (preview_row.source_route_selection_id <=> NEW.service_source_route_selection_id_snapshot)
          AND preview_row.target_plan_offering_id = NEW.plan_offering_id
          AND preview_row.target_route_selection_id = NEW.service_target_route_selection_id_snapshot
          AND preview_row.target_service_target_id = NEW.service_target_service_target_id_snapshot
          AND preview_row.target_protocol_profile_id = NEW.service_target_protocol_profile_id_snapshot
          AND preview_row.total_price_irr = NEW.base_price_irr
          AND preview_row.discount_eligible = NEW.offering_discount_eligible
          AND preview_row.state = 'previewed'
          AND preview_row.expires_at >= NEW.expires_at
          AND service_row.public_id = NEW.service_subscription_public_id
          AND service_row.user_id = NEW.user_id
          AND service_row.service_target_id = preview_row.source_service_target_id
          AND service_row.remote_identity_generation = preview_row.source_remote_identity_generation
          AND service_row.lifecycle_version = preview_row.source_lifecycle_version
          AND service_row.mutation_generation = preview_row.source_mutation_generation
          AND (service_row.route_selection_id <=> preview_row.source_route_selection_id)
          AND service_row.lifecycle_state IN ('active','suspended')
          AND service_row.remote_deleted_at IS NULL
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.remote_service_id IS NOT NULL
          AND target_offering.state = 'active'
          AND target_offering.visibility = 'visible'
          AND target_offering.id = NEW.plan_offering_id
          AND target_selection.plan_offering_id = NEW.plan_offering_id
          AND target_selection.user_id = NEW.user_id
          AND target_selection.selected_service_target_id = preview_row.target_service_target_id
          AND target_selection.panel_protocol_profile_id = preview_row.target_protocol_profile_id
          AND target_selection.capacity_reservation_id = preview_row.target_capacity_reservation_id
          AND reservation_row.state = 'held'
          AND reservation_row.expires_at >= NEW.expires_at;
        IF valid_reconfiguration_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration Quote authority is stale or invalid.';
        END IF;
    END IF;

    IF NEW.discount_irr > 0 AND NEW.offering_discount_eligible <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote offering does not allow discounts.';
    END IF;

    IF NEW.override_source = 'agent' THEN
        SELECT COUNT(*) INTO valid_override_count FROM agent_profiles a
        WHERE a.user_id = NEW.user_id AND a.status = 'active' AND a.pricing_profile_code = NEW.override_reference_code;
        IF valid_override_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote agent override reference is not current.'; END IF;
    ELSEIF NEW.override_source = 'tier' THEN
        SELECT COUNT(*) INTO valid_override_count
        FROM customer_profiles p INNER JOIN customer_tiers t ON t.id = p.current_tier_id
        WHERE p.user_id = NEW.user_id AND t.is_active = 1 AND t.code = NEW.override_reference_code;
        IF valid_override_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote tier override reference is not current.'; END IF;
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote configuration snapshot hash mismatch.';
    END IF;
END
