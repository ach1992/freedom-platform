CREATE OR REPLACE TRIGGER service_delivery_effects_insert_guard
BEFORE INSERT ON service_delivery_effects
FOR EACH ROW
BEGIN
    DECLARE valid_service_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_user_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_account_count INT DEFAULT 0;
    DECLARE unresolved_mutation_count INT DEFAULT 0;

    IF COALESCE(@app_service_delivery_effect_authority, '') <> 'service_delivery_effect_v1'
       OR NEW.service_delivery_attempt_id <> COALESCE(@app_service_delivery_effect_attempt_id, 0)
       OR NEW.service_subscription_id <> COALESCE(@app_service_delivery_effect_service_id, 0)
       OR BINARY NEW.public_id <> BINARY COALESCE(@app_service_delivery_effect_public_id, '')
       OR NEW.telegram_account_id <> COALESCE(@app_service_delivery_effect_telegram_account_id, 0)
       OR NEW.telegram_bot_id <> COALESCE(@app_service_delivery_effect_bot_id, 0)
       OR NEW.telegram_user_id <> COALESCE(@app_service_delivery_effect_telegram_user_id, 0)
       OR BINARY NEW.state <> BINARY 'prepared'
       OR NEW.state_version <> 1
       OR NEW.provider_boundary_started_at IS NOT NULL
       OR NEW.completed_at IS NOT NULL
       OR NEW.telegram_message_id IS NOT NULL
       OR NEW.result_code IS NOT NULL
       OR NEW.retry_after_seconds IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery effect creation authority is invalid.';
    END IF;

    SELECT service_row.id, service_row.user_id INTO valid_service_id, valid_user_id
    FROM service_delivery_attempts attempt_row
    JOIN service_subscriptions service_row ON service_row.id = attempt_row.service_subscription_id
    WHERE attempt_row.id = NEW.service_delivery_attempt_id
      AND service_row.provisioned_at IS NOT NULL
      AND service_row.service_target_id IS NOT NULL
      AND service_row.remote_service_id IS NOT NULL
      AND CHAR_LENGTH(service_row.remote_service_id) > 0
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended')
      AND service_row.remote_identity_generation = attempt_row.target_remote_identity_generation
      AND service_row.lifecycle_version = attempt_row.target_lifecycle_version
    LIMIT 1 FOR UPDATE;

    IF valid_service_id IS NULL OR valid_user_id IS NULL OR NEW.service_subscription_id <> valid_service_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery effect requires the current Delivery Attempt Service authority.';
    END IF;

    SELECT COUNT(*) INTO unresolved_mutation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.service_subscription_id = valid_service_id
      AND operation_row.operation_type <> 'initial_provision'
      AND operation_row.state NOT IN ('succeeded','failed_final','compensated');

    IF unresolved_mutation_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery effect is blocked by an unresolved Service mutation.';
    END IF;

    SELECT COUNT(*) INTO valid_account_count
    FROM telegram_accounts account_row
    WHERE account_row.id = NEW.telegram_account_id
      AND account_row.user_id = valid_user_id
      AND account_row.bot_id = NEW.telegram_bot_id
      AND account_row.telegram_user_id = NEW.telegram_user_id
      AND account_row.is_bot = 0;

    IF valid_account_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery effect Telegram recipient authority is invalid.';
    END IF;
END
