CREATE OR REPLACE TRIGGER provisioning_operations_delivery_effect_insert_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE sending_delivery_count INT DEFAULT 0;
    DECLARE pending_initial_delivery_count INT DEFAULT 0;

    IF NEW.operation_type IN ('reset_usage','suspend','activate','delete','rotate_subscription_link') THEN
        SELECT COUNT(*) INTO sending_delivery_count
        FROM service_delivery_effects effect_row
        WHERE effect_row.blocking_service_subscription_id = NEW.service_subscription_id;

        IF sending_delivery_count <> 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation is blocked while protected delivery is in-flight or uncertain after the Telegram provider boundary.';
        END IF;

        SELECT COUNT(*) INTO pending_initial_delivery_count
        FROM service_initial_delivery_fences fence_row
        WHERE fence_row.service_subscription_id = NEW.service_subscription_id;

        IF pending_initial_delivery_count <> 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation is blocked until the initial delivery attempt is durably scheduled.';
        END IF;
    END IF;
END
