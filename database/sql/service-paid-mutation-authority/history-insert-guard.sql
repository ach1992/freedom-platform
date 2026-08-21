CREATE OR REPLACE TRIGGER provisioning_operation_histories_insert_guard
BEFORE INSERT ON provisioning_operation_histories
FOR EACH ROW
BEGIN
    DECLARE valid_history_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT operation_row.id INTO valid_history_id
    FROM provisioning_operations operation_row
    WHERE operation_row.id = NEW.provisioning_operation_id
      AND operation_row.state = 'queued'
      AND operation_row.state_version = 1
      AND operation_row.correlation_id = NEW.correlation_id
      AND NEW.from_state IS NULL
      AND NEW.from_version IS NULL
      AND NEW.to_state = 'queued'
      AND NEW.to_version = 1
      AND NEW.actor_type = 'system'
      AND NEW.actor_id IS NULL
      AND ((operation_row.operation_type = 'initial_provision' AND operation_row.operation_generation = 0
            AND NEW.reason_code = 'initial_provisioning_requested')
           OR (operation_row.operation_type IN ('reset_usage','suspend','activate','delete','rotate_subscription_link','renew','add_data','add_days','add_data_days')
               AND operation_row.operation_generation >= 1
               AND NEW.reason_code = 'service_mutation_requested'))
    LIMIT 1;

    IF valid_history_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation history insertion is not authorized.';
    END IF;
END
