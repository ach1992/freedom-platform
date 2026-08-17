<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_unimplemented_source_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    IF NEW.source_type <> 'purchase' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source type has no active creation authority.';
    END IF;
END
SQL);

        // This independent fence is installed before 000100 relaxes the predecessor paid-only
        // Order shape. It therefore remains authoritative if MariaDB stops between 000100/101/102
        // or if the restartable provisioning migration re-enters that successor chain.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_purchase_lifecycle_insert_fence
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_quote_count INT DEFAULT 0;

    IF NEW.source_type = 'purchase' THEN
        IF NEW.state = 'awaiting_payment' AND NEW.state_version = 0 THEN
            IF NEW.purchase_settlement_id IS NOT NULL
               OR NEW.purchase_settlement_public_id IS NOT NULL
               OR NEW.payment_intent_id IS NOT NULL
               OR NEW.payment_intent_public_id IS NOT NULL
               OR NEW.settled_amount_irr IS NOT NULL
               OR NEW.paid_at IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order cannot claim captured financial authority.';
            END IF;

            SELECT COUNT(*) INTO valid_quote_count
            FROM quotes quote_row
            WHERE quote_row.id = NEW.source_quote_id
              AND quote_row.public_id = NEW.source_quote_public_id
              AND quote_row.user_id = NEW.user_id
              AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
              AND quote_row.final_price_irr = NEW.total_amount_irr
              AND quote_row.currency = NEW.currency
              AND quote_row.valid_from <= CURRENT_TIMESTAMP(6)
              AND quote_row.expires_at > CURRENT_TIMESTAMP(6);

            IF valid_quote_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order requires one current immutable purchase Quote.';
            END IF;
        ELSEIF NEW.state <> 'paid' OR NEW.state_version <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlement-backed Order insertion is restricted to paid/v1 authority.';
        END IF;
    END IF;
END
SQL);

        // The main history guard evolves in 000100, but immutable initial paid history must never
        // be writable unless the parent Order is already the exact paid/v1 financial authority.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_state_histories_purchase_authority_fence
BEFORE INSERT ON order_state_histories
FOR EACH ROW
BEGIN
    DECLARE valid_order_count INT DEFAULT 0;

    IF NEW.from_state IS NULL
       AND NEW.from_version IS NULL
       AND NEW.to_state = 'paid'
       AND NEW.to_version = 1
       AND NEW.reason_code = 'authoritative_purchase_settlement' THEN
        SELECT COUNT(*) INTO valid_order_count
        FROM orders order_row
        WHERE order_row.id = NEW.order_id
          AND order_row.source_type = 'purchase'
          AND order_row.state = 'paid'
          AND order_row.state_version = 1
          AND order_row.purchase_settlement_id IS NOT NULL
          AND order_row.purchase_settlement_public_id IS NOT NULL
          AND order_row.payment_intent_id IS NOT NULL
          AND order_row.payment_intent_public_id IS NOT NULL
          AND order_row.settled_amount_irr > 0
          AND order_row.paid_at IS NOT NULL
          AND order_row.creation_correlation_id = NEW.correlation_id;

        IF valid_order_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial paid Order history requires the matching paid/v1 purchase authority.';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_state_histories_purchase_authority_fence');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_purchase_lifecycle_insert_fence');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_unimplemented_source_insert_guard');
    }
};
