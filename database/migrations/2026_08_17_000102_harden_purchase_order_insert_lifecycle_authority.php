<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        $this->replaceInsertGuard(true);
    }

    public function down(): void
    {
        $this->replaceInsertGuard(false);
    }

    private function replaceInsertGuard(bool $strictLifecycle): void
    {
        $paidLifecycleGuard = $strictLifecycle
            ? <<<'SQL'
        IF NEW.state <> 'paid' OR NEW.state_version <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlement-backed Order insertion is restricted to paid/v1 authority.';
        END IF;

SQL
            : '';

        DB::unprepared(<<<SQL
CREATE OR REPLACE TRIGGER orders_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_purchase_count INT DEFAULT 0;

    IF NEW.source_type = 'purchase'
       AND NEW.state = 'awaiting_payment'
       AND NEW.state_version = 0 THEN
        IF NEW.purchase_settlement_id IS NOT NULL
           OR NEW.purchase_settlement_public_id IS NOT NULL
           OR NEW.payment_intent_id IS NOT NULL
           OR NEW.payment_intent_public_id IS NOT NULL
           OR NEW.settled_amount_irr IS NOT NULL
           OR NEW.paid_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order cannot claim captured financial authority.';
        END IF;

        SELECT COUNT(*) INTO valid_purchase_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id
          AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id
          AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.final_price_irr = NEW.total_amount_irr
          AND quote_row.currency = NEW.currency;

        IF valid_purchase_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order must match one immutable purchase Quote.';
        END IF;
    ELSEIF NEW.source_type = 'purchase' THEN
{$paidLifecycleGuard}        SELECT COUNT(*) INTO valid_purchase_count
        FROM purchase_settlements settlement_row
        INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
        INNER JOIN quotes quote_row ON quote_row.id = settlement_row.source_quote_id
        WHERE settlement_row.id = NEW.purchase_settlement_id
          AND settlement_row.public_id = NEW.purchase_settlement_public_id
          AND settlement_row.payment_intent_id = NEW.payment_intent_id
          AND settlement_row.user_id = NEW.user_id
          AND settlement_row.source_quote_id = NEW.source_quote_id
          AND settlement_row.source_quote_public_id = NEW.source_quote_public_id
          AND settlement_row.amount_irr = NEW.settled_amount_irr
          AND settlement_row.currency = NEW.currency
          AND settlement_row.settled_at = NEW.paid_at
          AND intent_row.id = NEW.payment_intent_id
          AND intent_row.public_id = NEW.payment_intent_public_id
          AND intent_row.purpose = 'purchase'
          AND intent_row.user_id = NEW.user_id
          AND intent_row.source_quote_id = NEW.source_quote_id
          AND intent_row.source_quote_public_id = NEW.source_quote_public_id
          AND intent_row.source_quote_configuration_hash = NEW.source_quote_configuration_hash
          AND intent_row.amount_irr = NEW.total_amount_irr
          AND intent_row.currency = NEW.currency
          AND intent_row.state IN ('captured','refund_pending','refunded','partially_refunded')
          AND intent_row.captured_at IS NOT NULL
          AND quote_row.id = NEW.source_quote_id
          AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id
          AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.final_price_irr = NEW.total_amount_irr
          AND quote_row.currency = NEW.currency;

        IF valid_purchase_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Order requires one matching authoritative purchase settlement.';
        END IF;
    END IF;
END
SQL);
    }
};
