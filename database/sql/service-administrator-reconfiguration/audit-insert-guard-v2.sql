CREATE OR REPLACE TRIGGER audit_logs_service_operational_insert_guard
BEFORE INSERT ON audit_logs
FOR EACH ROW
BEGIN
    DECLARE permission_code VARCHAR(64) DEFAULT NULL;
    DECLARE permission_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE administrator_count INT DEFAULT 0;
    DECLARE administrator_is_owner INT DEFAULT 0;
    DECLARE explicit_deny_count INT DEFAULT 0;
    DECLARE explicit_allow_count INT DEFAULT 0;
    DECLARE role_grant_count INT DEFAULT 0;
    DECLARE valid_context INT DEFAULT 0;

    IF NEW.action LIKE 'service.operational.%' THEN
        IF COALESCE(@app_service_operational_audit_authority, '') <> 'service_operational_audit_v1'
           OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
           OR NEW.actor_type <> 'administrator'
           OR NEW.actor_id IS NULL
           OR NEW.actor_id NOT REGEXP '^[1-9][0-9]*$'
           OR NEW.target_type IS NULL
           OR NEW.target_id IS NULL
           OR NEW.request_fingerprint IS NULL
           OR CHAR_LENGTH(NEW.request_fingerprint) <> 64
           OR BINARY NEW.request_fingerprint <> BINARY COALESCE(@app_service_operational_request_hash, '')
           OR NEW.correlation_id IS NULL
           OR NEW.reason_code IS NULL
           OR NEW.reason_code NOT REGEXP '^[a-z0-9_.-]{1,64}$'
           OR NEW.reason IS NULL
           OR CHAR_LENGTH(TRIM(NEW.reason)) = 0
           OR CHAR_LENGTH(NEW.reason) > 1000
           OR NEW.before_safe_data IS NULL
           OR NEW.after_safe_data IS NULL
           OR JSON_VALID(NEW.before_safe_data) <> 1
           OR JSON_VALID(NEW.after_safe_data) <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit authority is invalid.';
        END IF;

        SET permission_code = CASE NEW.action
            WHEN 'service.operational.import.attached' THEN 'services.import'
            WHEN 'service.operational.ownership.transferred' THEN 'services.transfer_ownership'
            WHEN 'service.operational.repair.applied' THEN 'services.repair'
            WHEN 'service.operational.batch.created' THEN 'services.grant_batch'
            WHEN 'service.operational.reconfiguration.queued' THEN 'services.reconfigure'
            ELSE NULL
        END;
        IF permission_code IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit action is not recognized.';
        END IF;

        SELECT COUNT(*), COALESCE(MAX(administrator_row.is_owner), 0)
          INTO administrator_count, administrator_is_owner
        FROM administrators administrator_row
        WHERE administrator_row.id = CAST(NEW.actor_id AS UNSIGNED)
          AND administrator_row.status = 'active';
        IF administrator_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit administrator is not active.';
        END IF;

        IF administrator_is_owner = 0 THEN
            SELECT MAX(permission_row.id) INTO permission_id
            FROM permissions permission_row
            WHERE permission_row.code = permission_code;
            IF permission_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit permission is not registered.';
            END IF;

            SELECT COUNT(*) INTO explicit_deny_count
            FROM administrator_permission_overrides override_row
            WHERE override_row.administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND override_row.permission_id = permission_id
              AND override_row.effect = 'deny';
            IF explicit_deny_count > 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit is denied by an explicit permission override.';
            END IF;

            SELECT COUNT(*) INTO explicit_allow_count
            FROM administrator_permission_overrides override_row
            WHERE override_row.administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND override_row.permission_id = permission_id
              AND override_row.effect = 'allow';

            SELECT COUNT(*) INTO role_grant_count
            FROM administrator_role_assignments assignment_row
            INNER JOIN roles role_row ON role_row.id = assignment_row.role_id
            INNER JOIN role_permissions role_permission_row ON role_permission_row.role_id = role_row.id
            WHERE assignment_row.administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND assignment_row.revoked_at IS NULL
              AND role_row.is_active = 1
              AND role_permission_row.permission_id = permission_id;
            IF explicit_allow_count = 0 AND role_grant_count = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit administrator lacks required permission.';
            END IF;
        END IF;

        IF NEW.action = 'service.operational.import.attached' THEN
            SELECT COUNT(*) INTO valid_context
            FROM service_imports import_row
            INNER JOIN order_source_authorizations source_row
                ON source_row.authorization_key = CONCAT('service-import:', import_row.public_id)
               AND source_row.source_type = 'admin_grant'
               AND source_row.user_id = import_row.user_id
               AND source_row.plan_offering_id = import_row.plan_offering_id
               AND source_row.actor_type = 'administrator'
               AND source_row.actor_id = import_row.actor_administrator_id
               AND BINARY source_row.correlation_id = BINARY import_row.correlation_id
            INNER JOIN orders order_row
                ON order_row.order_source_authorization_id = source_row.id
               AND order_row.source_type = 'admin_grant'
               AND order_row.user_id = import_row.user_id
               AND order_row.total_amount_irr = 0
               AND order_row.purchase_settlement_id IS NULL
               AND order_row.payment_intent_id IS NULL
            INNER JOIN order_items item_row
                ON item_row.order_id = order_row.id
               AND item_row.line_number = 1
               AND item_row.plan_offering_id = import_row.plan_offering_id
            INNER JOIN service_subscriptions service_row
                ON service_row.order_id = order_row.id
               AND service_row.order_item_id = item_row.id
               AND service_row.user_id = import_row.user_id
               AND service_row.service_target_id IS NULL
               AND service_row.remote_service_id IS NULL
            WHERE import_row.state = 'previewed'
              AND BINARY import_row.request_key_hash = BINARY NEW.request_fingerprint
              AND import_row.actor_administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND BINARY import_row.correlation_id = BINARY NEW.correlation_id
              AND NEW.target_type = 'service_subscription'
              AND BINARY NEW.target_id = BINARY service_row.public_id
              AND BINARY NEW.reason_code = BINARY source_row.reason_code
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.user_id')) AS UNSIGNED) = import_row.user_id
              AND JSON_TYPE(JSON_EXTRACT(NEW.before_safe_data, '$.service_target_id')) = 'NULL'
              AND JSON_TYPE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_service_id')) = 'NULL'
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.user_id')) AS UNSIGNED) = import_row.user_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.service_target_id')) AS UNSIGNED) = import_row.service_target_id
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(import_row.remote_service_id, 256)
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_canonical_hash')) = BINARY import_row.remote_canonical_hash;
        ELSEIF NEW.action = 'service.operational.ownership.transferred' THEN
            SELECT COUNT(*) INTO valid_context
            FROM service_ownership_transfers transfer_row
            INNER JOIN service_subscriptions service_row ON service_row.id = transfer_row.service_subscription_id
            WHERE transfer_row.state = 'pending'
              AND transfer_row.actor_administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND BINARY transfer_row.request_key_hash = BINARY NEW.request_fingerprint
              AND BINARY transfer_row.correlation_id = BINARY NEW.correlation_id
              AND service_row.user_id = transfer_row.from_user_id
              AND service_row.remote_identity_generation = transfer_row.target_remote_identity_generation
              AND service_row.lifecycle_version = transfer_row.target_lifecycle_version
              AND NEW.target_type = 'service_subscription'
              AND BINARY NEW.target_id = BINARY service_row.public_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.user_id')) AS UNSIGNED) = transfer_row.from_user_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = transfer_row.target_remote_identity_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_version')) AS UNSIGNED) = transfer_row.target_lifecycle_version
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.user_id')) AS UNSIGNED) = transfer_row.to_user_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = transfer_row.target_remote_identity_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.lifecycle_version')) AS UNSIGNED) = transfer_row.target_lifecycle_version + 1;
        ELSEIF NEW.action = 'service.operational.repair.applied' THEN
            SELECT COUNT(*) INTO valid_context
            FROM service_reconciliation_cases case_row
            INNER JOIN service_subscriptions service_row ON service_row.id = case_row.service_subscription_id
            WHERE case_row.state = 'previewed'
              AND case_row.remote_disposition = 'present'
              AND case_row.proposed_remote_service_id IS NOT NULL
              AND case_row.remote_canonical_hash IS NOT NULL
              AND case_row.actor_administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND BINARY case_row.request_key_hash = BINARY NEW.request_fingerprint
              AND BINARY case_row.correlation_id = BINARY NEW.correlation_id
              AND service_row.service_target_id = case_row.service_target_id
              AND BINARY service_row.remote_service_id = BINARY case_row.before_remote_service_id
              AND service_row.remote_identity_generation = case_row.target_remote_identity_generation
              AND service_row.lifecycle_version = case_row.target_lifecycle_version
              AND NEW.target_type = 'service_subscription'
              AND BINARY NEW.target_id = BINARY service_row.public_id
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(case_row.before_remote_service_id, 256)
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = case_row.target_remote_identity_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_version')) AS UNSIGNED) = case_row.target_lifecycle_version
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(case_row.proposed_remote_service_id, 256)
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_canonical_hash')) = BINARY case_row.remote_canonical_hash
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = case_row.target_remote_identity_generation + 1
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.lifecycle_version')) AS UNSIGNED) = case_row.target_lifecycle_version + 1;
        ELSEIF NEW.action = 'service.operational.reconfiguration.queued' THEN
            SELECT COUNT(*) INTO valid_context
            FROM service_reconfiguration_previews preview_row
            INNER JOIN service_subscriptions service_row
                ON service_row.id = preview_row.service_subscription_id
            WHERE preview_row.actor_administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND preview_row.actor_user_id = service_row.user_id
              AND BINARY preview_row.request_key_hash = BINARY NEW.request_fingerprint
              AND BINARY preview_row.correlation_id = BINARY NEW.correlation_id
              AND BINARY preview_row.administrator_reason_code = BINARY NEW.reason_code
              AND BINARY preview_row.administrator_reason = BINARY NEW.reason
              AND preview_row.total_price_irr = 0
              AND preview_row.state = 'previewed'
              AND preview_row.expires_at > CURRENT_TIMESTAMP(6)
              AND service_row.service_target_id = preview_row.source_service_target_id
              AND (service_row.route_selection_id <=> preview_row.source_route_selection_id)
              AND service_row.remote_identity_generation = preview_row.source_remote_identity_generation
              AND service_row.lifecycle_version = preview_row.source_lifecycle_version
              AND service_row.mutation_generation = preview_row.source_mutation_generation
              AND service_row.lifecycle_state IN ('active','suspended')
              AND service_row.remote_deleted_at IS NULL
              AND service_row.provisioned_at IS NOT NULL
              AND NEW.target_type = 'service_subscription'
              AND BINARY NEW.target_id = BINARY service_row.public_id
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.preview_public_id')) = BINARY preview_row.public_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.source_service_target_id')) AS UNSIGNED) = preview_row.source_service_target_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.source_remote_identity_generation')) AS UNSIGNED) = preview_row.source_remote_identity_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.source_lifecycle_version')) AS UNSIGNED) = preview_row.source_lifecycle_version
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.source_mutation_generation')) AS UNSIGNED) = preview_row.source_mutation_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.target_route_selection_id')) AS UNSIGNED) = preview_row.target_route_selection_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.target_service_target_id')) AS UNSIGNED) = preview_row.target_service_target_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.target_protocol_profile_id')) AS UNSIGNED) = preview_row.target_protocol_profile_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.total_price_irr')) AS UNSIGNED) = 0;
        ELSEIF NEW.action = 'service.operational.batch.created' THEN
            IF NEW.target_type = 'service_batch_grant'
               AND NEW.target_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
               AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.state')) = BINARY 'active'
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.item_count')) AS UNSIGNED) BETWEEN 1 AND 50
               AND JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.payload_hash')) REGEXP '^[0-9a-f]{64}$' THEN
                SET valid_context = 1;
            END IF;
        END IF;

        IF valid_context <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit is detached from exact operational authority.';
        END IF;
    END IF;
END
