CREATE OR REPLACE TRIGGER service_subscriptions_insert_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_authority_order_id BIGINT UNSIGNED DEFAULT NULL;

    IF NEW.route_selection_id IS NOT NULL
       OR NEW.service_target_id IS NOT NULL
       OR NEW.remote_service_id IS NOT NULL
       OR NEW.provisioned_at IS NOT NULL
       OR NEW.lifecycle_state <> 'active'
       OR NEW.lifecycle_version <> 0
       OR NEW.remote_identity_generation <> 1
       OR NEW.mutation_generation <> 0
       OR NEW.remote_deleted_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription must start with a clean local lifecycle and no remote binding.';
    END IF;

    SELECT purchase_settlement_id, payment_intent_id INTO authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires one currently captured authoritative purchase Order Item.';
    END IF;

    SELECT id INTO locked_settlement_id FROM purchase_settlements WHERE id = authority_settlement_id LIMIT 1 FOR UPDATE;
    SELECT id INTO locked_intent_id FROM payment_intents WHERE id = authority_intent_id LIMIT 1 FOR UPDATE;

    SELECT order_row.id INTO valid_authority_order_id
    FROM orders order_row
    INNER JOIN order_items item_row ON item_row.id = NEW.order_item_id
    INNER JOIN purchase_settlements settlement_row ON settlement_row.id = order_row.purchase_settlement_id
    INNER JOIN payment_intents intent_row ON intent_row.id = order_row.payment_intent_id
    WHERE order_row.id = NEW.order_id
      AND settlement_row.id = locked_settlement_id
      AND intent_row.id = locked_intent_id
      AND item_row.order_id = order_row.id
      AND item_row.line_number = 1
      AND order_row.user_id = NEW.user_id
      AND order_row.source_type = 'purchase'
      AND order_row.state = 'paid'
      AND order_row.state_version = 1
      AND settlement_row.payment_intent_id = intent_row.id
      AND settlement_row.user_id = order_row.user_id
      AND settlement_row.source_quote_id = order_row.source_quote_id
      AND settlement_row.public_id = order_row.purchase_settlement_public_id
      AND intent_row.public_id = order_row.payment_intent_public_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.wallet_account_id IS NULL
      AND intent_row.user_id = order_row.user_id
      AND intent_row.source_quote_id = order_row.source_quote_id
      AND intent_row.source_quote_public_id = order_row.source_quote_public_id
      AND intent_row.source_quote_configuration_hash = order_row.source_quote_configuration_hash
      AND intent_row.amount_irr = order_row.total_amount_irr
      AND intent_row.currency = order_row.currency
      AND intent_row.state = 'captured'
      AND intent_row.captured_at IS NOT NULL
    LIMIT 1
    FOR UPDATE;

    IF valid_authority_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires one currently captured authoritative purchase Order Item.';
    END IF;
END
