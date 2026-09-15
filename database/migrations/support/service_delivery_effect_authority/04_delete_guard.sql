CREATE OR REPLACE TRIGGER service_delivery_effects_delete_guard
BEFORE DELETE ON service_delivery_effects
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service delivery effect evidence is non-deletable.';
END
