CREATE OR REPLACE TRIGGER provisioning_operation_initial_history
AFTER INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    INSERT INTO provisioning_operation_histories (
        provisioning_operation_id, from_state, to_state, from_version, to_version,
        actor_type, actor_id, reason_code, correlation_id, created_at
    ) VALUES (
        NEW.id, NULL, NEW.state, NULL, NEW.state_version,
        'system', NULL,
        IF(NEW.operation_type = 'initial_provision', 'initial_provisioning_requested', 'service_mutation_requested'),
        NEW.correlation_id, NEW.created_at
    );
END
