<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement C2C-002 C2C-005 WAL-004 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_insert_guard');
        $this->createC2cAwarePurchaseRefundGuard();
    }

    public function down(): void
    {
        $hasC2cRefund = DB::table('purchase_refunds as refund_row')
            ->join('purchase_settlements as settlement_row', 'settlement_row.id', '=', 'refund_row.purchase_settlement_id')
            ->where('settlement_row.provider_code', 'card_to_card')
            ->exists();
        if ($hasC2cRefund) {
            throw new RuntimeException('Cannot roll back C2C refundable-amount authority while card-to-card refunds exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_insert_guard');
        $this->createPriorPurchaseRefundGuard();
    }

    private function createC2cAwarePurchaseRefundGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_refunds_insert_guard
BEFORE INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE refundable_amount BIGINT DEFAULT NULL;
    DECLARE prior_refunded BIGINT DEFAULT 0;
    DECLARE valid_authority_count INT DEFAULT 0;
    DECLARE current_intent_state VARCHAR(32) DEFAULT NULL;
    DECLARE current_latest_refund_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE latest_cumulative BIGINT DEFAULT NULL;

    SELECT
        CASE
            WHEN settlement_row.provider_code = 'card_to_card' THEN reservation_row.base_amount_irr
            ELSE settlement_row.amount_irr
        END,
        intent_row.state,
        intent_row.latest_purchase_refund_id
    INTO refundable_amount, current_intent_state, current_latest_refund_id
    FROM purchase_settlements settlement_row
    INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
    LEFT JOIN c2c_transaction_matches match_row
      ON match_row.purchase_settlement_id = settlement_row.id
     AND match_row.payment_intent_id = settlement_row.payment_intent_id
     AND match_row.state = 'captured'
    LEFT JOIN c2c_amount_reservations reservation_row
      ON reservation_row.id = match_row.c2c_amount_reservation_id
     AND reservation_row.payment_intent_id = settlement_row.payment_intent_id
    WHERE settlement_row.id = NEW.purchase_settlement_id
      AND settlement_row.payment_intent_id = NEW.payment_intent_id
      AND settlement_row.user_id = NEW.user_id
      AND settlement_row.provider_code = NEW.provider_code
      AND settlement_row.currency = NEW.currency
      AND intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.user_id = NEW.user_id
      AND intent_row.currency = NEW.currency
      AND intent_row.captured_at IS NOT NULL
      AND (
          (settlement_row.provider_code <> 'card_to_card'
              AND intent_row.amount_irr = settlement_row.amount_irr)
          OR
          (settlement_row.provider_code = 'card_to_card'
              AND match_row.id IS NOT NULL
              AND reservation_row.id IS NOT NULL
              AND reservation_row.base_amount_irr = intent_row.amount_irr
              AND reservation_row.payable_amount_irr = settlement_row.amount_irr)
      )
    FOR UPDATE;

    IF refundable_amount IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund requires one matching authoritative refundable purchase settlement.';
    END IF;

    IF current_intent_state NOT IN ('captured','partially_refunded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund requires captured or partially refunded payment state.';
    END IF;

    SELECT COUNT(*) INTO valid_authority_count
    FROM payment_provider_events provider_event
    WHERE provider_event.id = NEW.provider_event_row_id
      AND provider_event.payment_intent_id = NEW.payment_intent_id
      AND provider_event.provider_code = NEW.provider_code
      AND provider_event.provider_transaction_id = NEW.provider_refund_id
      AND provider_event.evidence_payload_hash = NEW.evidence_payload_hash
      AND provider_event.evidence_authority = 'authoritative'
      AND provider_event.transaction_status IN ('refunded','reversed')
      AND provider_event.amount_irr = NEW.amount_irr
      AND provider_event.currency = NEW.currency
      AND provider_event.occurred_at = NEW.refunded_at;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund requires one matching authoritative provider refund event.';
    END IF;

    SELECT COALESCE(SUM(amount_irr), 0) INTO prior_refunded
    FROM purchase_refunds
    WHERE purchase_settlement_id = NEW.purchase_settlement_id;

    IF current_intent_state = 'captured' THEN
        IF prior_refunded <> 0 OR current_latest_refund_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured purchase refund chain must start from zero.';
        END IF;
    ELSE
        IF current_latest_refund_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Partially refunded purchase requires an authoritative latest refund pointer.';
        END IF;

        SELECT cumulative_refunded_irr INTO latest_cumulative
        FROM purchase_refunds
        WHERE id = current_latest_refund_id
          AND purchase_settlement_id = NEW.purchase_settlement_id
          AND payment_intent_id = NEW.payment_intent_id;

        IF latest_cumulative IS NULL OR latest_cumulative <> prior_refunded THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund chain cumulative authority is inconsistent.';
        END IF;
    END IF;

    IF prior_refunded > refundable_amount
       OR NEW.amount_irr > refundable_amount - prior_refunded
       OR NEW.cumulative_refunded_irr <> prior_refunded + NEW.amount_irr THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund would exceed authoritative refundable captured amount.';
    END IF;

    IF (NEW.cumulative_refunded_irr = refundable_amount AND NEW.resulting_payment_state <> 'refunded')
       OR (NEW.cumulative_refunded_irr < refundable_amount AND NEW.resulting_payment_state <> 'partially_refunded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund resulting payment state is inconsistent.';
    END IF;
END
SQL);
    }

    private function createPriorPurchaseRefundGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_refunds_insert_guard
BEFORE INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE settlement_amount BIGINT DEFAULT NULL;
    DECLARE prior_refunded BIGINT DEFAULT 0;
    DECLARE valid_authority_count INT DEFAULT 0;
    DECLARE current_intent_state VARCHAR(32) DEFAULT NULL;
    DECLARE current_latest_refund_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE latest_cumulative BIGINT DEFAULT NULL;

    SELECT settlement_row.amount_irr, intent_row.state, intent_row.latest_purchase_refund_id
    INTO settlement_amount, current_intent_state, current_latest_refund_id
    FROM purchase_settlements settlement_row
    INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
    WHERE settlement_row.id = NEW.purchase_settlement_id
      AND settlement_row.payment_intent_id = NEW.payment_intent_id
      AND settlement_row.user_id = NEW.user_id
      AND settlement_row.provider_code = NEW.provider_code
      AND settlement_row.currency = NEW.currency
      AND intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.user_id = NEW.user_id
      AND intent_row.amount_irr = settlement_row.amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.captured_at IS NOT NULL
    FOR UPDATE;

    IF settlement_amount IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund requires one matching authoritative purchase settlement.';
    END IF;

    IF current_intent_state NOT IN ('captured','partially_refunded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund requires captured or partially refunded payment state.';
    END IF;

    SELECT COUNT(*) INTO valid_authority_count
    FROM payment_provider_events provider_event
    WHERE provider_event.id = NEW.provider_event_row_id
      AND provider_event.payment_intent_id = NEW.payment_intent_id
      AND provider_event.provider_code = NEW.provider_code
      AND provider_event.provider_transaction_id = NEW.provider_refund_id
      AND provider_event.evidence_payload_hash = NEW.evidence_payload_hash
      AND provider_event.evidence_authority = 'authoritative'
      AND provider_event.transaction_status IN ('refunded','reversed')
      AND provider_event.amount_irr = NEW.amount_irr
      AND provider_event.currency = NEW.currency
      AND provider_event.occurred_at = NEW.refunded_at;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund requires one matching authoritative provider refund event.';
    END IF;

    SELECT COALESCE(SUM(amount_irr), 0) INTO prior_refunded
    FROM purchase_refunds
    WHERE purchase_settlement_id = NEW.purchase_settlement_id;

    IF current_intent_state = 'captured' THEN
        IF prior_refunded <> 0 OR current_latest_refund_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured purchase refund chain must start from zero.';
        END IF;
    ELSE
        IF current_latest_refund_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Partially refunded purchase requires an authoritative latest refund pointer.';
        END IF;

        SELECT cumulative_refunded_irr INTO latest_cumulative
        FROM purchase_refunds
        WHERE id = current_latest_refund_id
          AND purchase_settlement_id = NEW.purchase_settlement_id
          AND payment_intent_id = NEW.payment_intent_id;

        IF latest_cumulative IS NULL OR latest_cumulative <> prior_refunded THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund chain cumulative authority is inconsistent.';
        END IF;
    END IF;

    IF prior_refunded > settlement_amount
       OR NEW.amount_irr > settlement_amount - prior_refunded
       OR NEW.cumulative_refunded_irr <> prior_refunded + NEW.amount_irr THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund would exceed authoritative captured amount.';
    END IF;

    IF (NEW.cumulative_refunded_irr = settlement_amount AND NEW.resulting_payment_state <> 'refunded')
       OR (NEW.cumulative_refunded_irr < settlement_amount AND NEW.resulting_payment_state <> 'partially_refunded') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund resulting payment state is inconsistent.';
    END IF;
END
SQL);
    }
};
