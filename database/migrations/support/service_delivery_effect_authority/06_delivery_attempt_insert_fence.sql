CREATE OR REPLACE TRIGGER service_delivery_attempts_effect_fence_insert_guard
BEFORE INSERT ON service_delivery_attempts
FOR EACH ROW
BEGIN
    DECLARE blocked_delivery_count INT DEFAULT 0;

    SELECT COUNT(*) INTO blocked_delivery_count
    FROM service_delivery_effects effect_row
    WHERE effect_row.blocking_service_subscription_id = NEW.service_subscription_id;

    IF blocked_delivery_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New Service delivery is blocked by an in-flight, uncertain, or provider-directed retry boundary.';
    END IF;
END
