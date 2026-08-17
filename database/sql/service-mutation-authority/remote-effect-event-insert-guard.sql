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
      AND ((operation_row.operation_type = 'initial_provision'
            AND COALESCE(@app_provisioning_authority, '') = 'initial_remote_effect_v1'
            AND NEW.event_type IN ('claimed','route_bound','succeeded','retry_scheduled','uncertain_remote_result','reconciliation_scheduled','needs_review','failed_final'))
           OR (operation_row.operation_type IN ('reset_usage','suspend','activate','delete','rotate_subscription_link')
               AND COALESCE(@app_provisioning_authority, '') = 'service_mutation_effect_v1'
               AND operation_row.operation_generation = COALESCE(@app_service_mutation_generation, 0)
               AND NEW.event_type IN ('claimed','succeeded','retry_scheduled','uncertain_remote_result','needs_review','failed_final')));

    IF valid_operation_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect event authority is inconsistent.';
    END IF;
END
