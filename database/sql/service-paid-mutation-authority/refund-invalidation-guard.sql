CREATE OR REPLACE TRIGGER purchase_refunds_provisioning_invalidation
AFTER INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE running_operation_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE existing_invalidation_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE existing_purchase_refund_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_existing_refund_count INT DEFAULT 0;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_order_id IS NOT NULL THEN
        SELECT operation_row.id INTO running_operation_id
        FROM provisioning_operations operation_row
        WHERE operation_row.order_id = locked_order_id
          AND operation_row.state = 'running'
          AND (
              operation_row.operation_type = 'initial_provision'
              OR EXISTS (
                  SELECT 1
                  FROM service_paid_mutation_authorities paid_authority
                  WHERE paid_authority.provisioning_operation_id = operation_row.id
              )
          )
        LIMIT 1
        FOR UPDATE;

        IF running_operation_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning remote-effect fence is active; retry refund after reconciliation.';
        END IF;
    END IF;

    SELECT id, purchase_refund_id
    INTO existing_invalidation_id, existing_purchase_refund_id
    FROM provisioning_financial_invalidations
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF existing_invalidation_id IS NOT NULL THEN
        SELECT COUNT(*) INTO valid_existing_refund_count
        FROM purchase_refunds
        WHERE id = existing_purchase_refund_id
          AND purchase_settlement_id = NEW.purchase_settlement_id
          AND payment_intent_id = NEW.payment_intent_id;

        IF valid_existing_refund_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing provisioning financial invalidation binding is inconsistent.';
        END IF;
    ELSE
        INSERT INTO provisioning_financial_invalidations (
            order_id, purchase_settlement_id, payment_intent_id, purchase_refund_id, created_at
        ) VALUES (
            locked_order_id, NEW.purchase_settlement_id, NEW.payment_intent_id, NEW.id, CURRENT_TIMESTAMP(6)
        );
    END IF;
END
