<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        // Financial invalidation is meaningful only for purchase-backed Orders. The canonical
        // Service/Provisioning insert guards independently validate trial, benefit-code and
        // administrator-grant source authority; these companion guards must not require a
        // fabricated settlement/payment identity for a legitimate zero-cost Order.
        $this->replaceServiceInvalidationGuard();
        $this->replaceProvisioningOperationInvalidationGuard();
    }

    public function down(): void
    {
        if (DB::table('orders')->whereIn('source_type', ['trial', 'benefit_code', 'admin_grant'])->exists()) {
            throw new RuntimeException('Cannot restore purchase-only provisioning invalidation guards while non-paid Orders exist.');
        }

        $this->restorePurchaseOnlyServiceInvalidationGuard();
        $this->restorePurchaseOnlyProvisioningOperationInvalidationGuard();
    }

    private function replaceServiceInvalidationGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_financial_invalidation_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE source_type_value VARCHAR(32) DEFAULT NULL;
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT source_type, purchase_settlement_id, payment_intent_id
    INTO source_type_value, authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF source_type_value IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription financial invalidation authority requires an existing Order.';
    END IF;

    IF source_type_value = 'purchase' THEN
        IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires authoritative purchase financial identity.';
        END IF;

        SELECT id INTO locked_settlement_id
        FROM purchase_settlements
        WHERE id = authority_settlement_id
        LIMIT 1
        FOR UPDATE;

        SELECT id INTO locked_intent_id
        FROM payment_intents
        WHERE id = authority_intent_id
        LIMIT 1
        FOR UPDATE;

        SELECT id INTO locked_order_id
        FROM orders
        WHERE id = NEW.order_id
          AND source_type = 'purchase'
          AND purchase_settlement_id = locked_settlement_id
          AND payment_intent_id = locked_intent_id
        LIMIT 1
        FOR UPDATE;

        IF locked_settlement_id IS NULL OR locked_intent_id IS NULL OR locked_order_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription financial authority is unavailable.';
        END IF;

        SELECT invalidation_row.id INTO invalidation_id
        FROM provisioning_financial_invalidations invalidation_row
        INNER JOIN purchase_refunds refund_row
            ON refund_row.id = invalidation_row.purchase_refund_id
           AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
           AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
        WHERE invalidation_row.purchase_settlement_id = locked_settlement_id
          AND invalidation_row.payment_intent_id = locked_intent_id
        LIMIT 1
        FOR UPDATE;

        IF invalidation_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create a Service Subscription.';
        END IF;
    ELSEIF source_type_value IN ('trial', 'benefit_code', 'admin_grant') THEN
        IF authority_settlement_id IS NOT NULL OR authority_intent_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-paid Service Subscription authority cannot claim purchase financial identity.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription financial invalidation authority does not support this Order source.';
    END IF;
END
SQL);
    }

    private function replaceProvisioningOperationInvalidationGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_financial_invalidation_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE source_type_value VARCHAR(32) DEFAULT NULL;
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT source_type, purchase_settlement_id, payment_intent_id
    INTO source_type_value, authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF source_type_value IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation financial invalidation authority requires an existing Order.';
    END IF;

    IF source_type_value = 'purchase' THEN
        IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires authoritative purchase financial identity.';
        END IF;

        SELECT id INTO locked_settlement_id
        FROM purchase_settlements
        WHERE id = authority_settlement_id
        LIMIT 1
        FOR UPDATE;

        SELECT id INTO locked_intent_id
        FROM payment_intents
        WHERE id = authority_intent_id
        LIMIT 1
        FOR UPDATE;

        SELECT id INTO locked_order_id
        FROM orders
        WHERE id = NEW.order_id
          AND source_type = 'purchase'
          AND purchase_settlement_id = locked_settlement_id
          AND payment_intent_id = locked_intent_id
        LIMIT 1
        FOR UPDATE;

        IF locked_settlement_id IS NULL OR locked_intent_id IS NULL OR locked_order_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation financial authority is unavailable.';
        END IF;

        SELECT invalidation_row.id INTO invalidation_id
        FROM provisioning_financial_invalidations invalidation_row
        INNER JOIN purchase_refunds refund_row
            ON refund_row.id = invalidation_row.purchase_refund_id
           AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
           AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
        WHERE invalidation_row.purchase_settlement_id = locked_settlement_id
          AND invalidation_row.payment_intent_id = locked_intent_id
        LIMIT 1
        FOR UPDATE;

        IF invalidation_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create an initial Provisioning Operation.';
        END IF;
    ELSEIF source_type_value IN ('trial', 'benefit_code', 'admin_grant') THEN
        IF authority_settlement_id IS NOT NULL OR authority_intent_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-paid Provisioning Operation authority cannot claim purchase financial identity.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation financial invalidation authority does not support this Order source.';
    END IF;
END
SQL);
    }

    private function restorePurchaseOnlyServiceInvalidationGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_financial_invalidation_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT purchase_settlement_id, payment_intent_id
    INTO authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires authoritative purchase financial identity.';
    END IF;

    SELECT id INTO locked_settlement_id
    FROM purchase_settlements
    WHERE id = authority_settlement_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_intent_id
    FROM payment_intents
    WHERE id = authority_intent_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE id = NEW.order_id
      AND purchase_settlement_id = locked_settlement_id
      AND payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_settlement_id IS NULL OR locked_intent_id IS NULL OR locked_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription financial authority is unavailable.';
    END IF;

    SELECT invalidation_row.id INTO invalidation_id
    FROM provisioning_financial_invalidations invalidation_row
    INNER JOIN purchase_refunds refund_row
        ON refund_row.id = invalidation_row.purchase_refund_id
       AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
       AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
    WHERE invalidation_row.purchase_settlement_id = locked_settlement_id
      AND invalidation_row.payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF invalidation_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create a Service Subscription.';
    END IF;
END
SQL);
    }

    private function restorePurchaseOnlyProvisioningOperationInvalidationGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_financial_invalidation_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT purchase_settlement_id, payment_intent_id
    INTO authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires authoritative purchase financial identity.';
    END IF;

    SELECT id INTO locked_settlement_id
    FROM purchase_settlements
    WHERE id = authority_settlement_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_intent_id
    FROM payment_intents
    WHERE id = authority_intent_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE id = NEW.order_id
      AND purchase_settlement_id = locked_settlement_id
      AND payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_settlement_id IS NULL OR locked_intent_id IS NULL OR locked_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation financial authority is unavailable.';
    END IF;

    SELECT invalidation_row.id INTO invalidation_id
    FROM provisioning_financial_invalidations invalidation_row
    INNER JOIN purchase_refunds refund_row
        ON refund_row.id = invalidation_row.purchase_refund_id
       AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
       AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
    WHERE invalidation_row.purchase_settlement_id = locked_settlement_id
      AND invalidation_row.payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF invalidation_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create an initial Provisioning Operation.';
    END IF;
END
SQL);
    }
};
