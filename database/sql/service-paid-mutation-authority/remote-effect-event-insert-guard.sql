CREATE OR REPLACE TRIGGER provisioning_remote_effect_events_insert_guard
BEFORE INSERT ON provisioning_remote_effect_events
FOR EACH ROW
BEGIN
    DECLARE valid_operation_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_operation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.id = NEW.provisioning_operation_id
      AND BINARY operation_row.operation_key = BINARY COALESCE(@app_provisioning_operation_key, '')
      AND BINARY operation_row.correlation_id = BINARY COALESCE(@app_provisioning_correlation_id, '')
      AND BINARY NEW.correlation_id = BINARY operation_row.correlation_id
      AND NEW.state_version = operation_row.state_version
      AND (NEW.route_selection_id <=> operation_row.route_selection_id)
      AND (NEW.service_target_id <=> operation_row.service_target_id)
      AND (NEW.remote_service_id <=> operation_row.remote_service_id)
      AND ((operation_row.operation_type = 'initial_provision'
            AND COALESCE(@app_provisioning_authority, '') = 'initial_remote_effect_v1'
            AND (
                (NEW.event_type IN ('claimed','route_bound')
                    AND operation_row.state = 'running'
                    AND NEW.result_code IS NULL)
                OR (NEW.event_type = 'reconciliation_scheduled'
                    AND operation_row.state = 'retry_scheduled'
                    AND BINARY NEW.result_code = BINARY 'uncertain_recovery')
                OR (NEW.event_type IN ('succeeded','retry_scheduled','uncertain_remote_result','needs_review','failed_final')
                    AND BINARY NEW.event_type = BINARY operation_row.state
                    AND (NEW.result_code <=> operation_row.last_result_code))
            ))
           OR (operation_row.operation_type IN ('reset_usage','suspend','activate','delete','rotate_subscription_link','renew','add_data','add_days','add_data_days')
               AND COALESCE(@app_provisioning_authority, '') = 'service_mutation_effect_v1'
               AND operation_row.operation_generation = COALESCE(@app_service_mutation_generation, 0)
               AND (
                   (NEW.event_type = 'claimed'
                       AND operation_row.state = 'running'
                       AND NEW.result_code IS NULL)
                   OR (NEW.event_type IN ('succeeded','retry_scheduled','uncertain_remote_result','needs_review','failed_final')
                       AND BINARY NEW.event_type = BINARY operation_row.state
                       AND (NEW.result_code <=> operation_row.last_result_code))
               )));

    IF valid_operation_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event authority is inconsistent.';
    END IF;
END
