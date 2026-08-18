CREATE OR REPLACE TRIGGER service_delivery_attempts_effect_fence_insert_guard
BEFORE INSERT ON service_delivery_attempts
FOR EACH ROW
BEGIN
    DECLARE blocked_delivery_count INT DEFAULT 0;
    DECLARE pending_initial_delivery_count INT DEFAULT 0;
    DECLARE matching_initial_delivery_count INT DEFAULT 0;

    SELECT COUNT(*) INTO blocked_delivery_count
    FROM service_delivery_effects effect_row
    WHERE effect_row.blocking_service_subscription_id = NEW.service_subscription_id;

    IF blocked_delivery_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New Service delivery is blocked by an in-flight, uncertain, or provider-directed retry boundary.';
    END IF;

    SELECT COUNT(*) INTO pending_initial_delivery_count
    FROM service_initial_delivery_fences fence_row
    WHERE fence_row.service_subscription_id = NEW.service_subscription_id;

    IF pending_initial_delivery_count <> 0 THEN
        SELECT COUNT(*) INTO matching_initial_delivery_count
        FROM service_initial_delivery_fences fence_row
        INNER JOIN provisioning_operations operation_row
            ON operation_row.id = fence_row.provisioning_operation_id
        WHERE fence_row.service_subscription_id = NEW.service_subscription_id
          AND NEW.purpose = 'initial'
          AND BINARY NEW.request_key_hash = BINARY LOWER(SHA2(CONCAT('initial-delivery:', operation_row.public_id), 256))
          AND BINARY NEW.correlation_id = BINARY operation_row.correlation_id;

        IF matching_initial_delivery_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the deterministic initial Delivery Attempt may cross a pending initial-delivery fence.';
        END IF;
    END IF;
END
