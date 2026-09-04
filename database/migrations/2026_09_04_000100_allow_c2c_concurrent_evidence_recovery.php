<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement C2C-003 C2C-004 PAY-002 PRO-001 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_manual_submissions_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_settlement_count INT DEFAULT 0;
    DECLARE valid_refund_pointer_count INT DEFAULT 0;
    DECLARE pointed_result_state VARCHAR(32) DEFAULT NULL;
    DECLARE valid_wallet_cancel_count INT DEFAULT 0;
    DECLARE valid_c2c_evidence_recovery_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.creation_key <=> OLD.creation_key)
       OR NOT (NEW.payload_hash <=> OLD.payload_hash)
       OR NOT (NEW.purpose <=> OLD.purpose)
       OR NOT (NEW.user_id <=> OLD.user_id)
       OR NOT (NEW.wallet_account_id <=> OLD.wallet_account_id)
       OR NOT (NEW.source_quote_id <=> OLD.source_quote_id)
       OR NOT (NEW.source_quote_public_id <=> OLD.source_quote_public_id)
       OR NOT (NEW.source_quote_configuration_hash <=> OLD.source_quote_configuration_hash)
       OR NOT (NEW.payment_eligibility_decision_id <=> OLD.payment_eligibility_decision_id)
       OR NOT (NEW.payment_eligibility_decision_public_id <=> OLD.payment_eligibility_decision_public_id)
       OR NOT (NEW.payment_eligibility_configuration_hash <=> OLD.payment_eligibility_configuration_hash)
       OR NOT (NEW.payment_eligibility_method_configuration_hash <=> OLD.payment_eligibility_method_configuration_hash)
       OR NOT (NEW.payment_method_version_id <=> OLD.payment_method_version_id)
       OR NOT (NEW.payment_method_code <=> OLD.payment_method_code)
       OR NOT (NEW.payment_method_version <=> OLD.payment_method_version)
       OR NOT (NEW.payment_method_configuration_hash <=> OLD.payment_method_configuration_hash)
       OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.creation_correlation_id <=> OLD.creation_correlation_id)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent financial identity is immutable.';
    END IF;

    IF NOT (NEW.latest_purchase_refund_id <=> OLD.latest_purchase_refund_id)
       AND NOT (NEW.purpose = 'purchase'
                AND OLD.state IN ('captured','partially_refunded')
                AND NEW.state = 'refund_pending') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent refund authority pointer can change only when a new purchase refund enters pending transition.';
    END IF;

    IF OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' THEN
        SELECT COUNT(*) INTO valid_wallet_cancel_count
        FROM purchase_wallet_reservations reservation_row
        INNER JOIN wallet_holds hold_row ON hold_row.id = reservation_row.wallet_hold_id
        WHERE reservation_row.payment_intent_id = OLD.id
          AND OLD.purpose = 'purchase'
          AND OLD.provider_code = 'wallet'
          AND OLD.payment_method_code = 'wallet'
          AND OLD.wallet_account_id IS NULL
          AND hold_row.ledger_account_id = reservation_row.wallet_account_id
          AND hold_row.source_type = 'payment_intent'
          AND hold_row.source_id = OLD.public_id
          AND hold_row.status = 'released'
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = OLD.id
          );

        IF valid_wallet_cancel_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase cancellation requires its exact released hold and no settlement.';
        END IF;
    END IF;

    IF OLD.state = 'expired' AND NEW.state = 'submitted'
       AND OLD.purpose = 'purchase'
       AND OLD.payment_method_code = 'card_to_card'
       AND OLD.provider_code = 'card_to_card'
       AND OLD.wallet_account_id IS NULL
       AND OLD.captured_at IS NULL
       AND COALESCE(IS_USED_LOCK('freedom:c2c-evidence-recovery:v1'), -1) = CONNECTION_ID() THEN
        SELECT COUNT(*) INTO valid_c2c_evidence_recovery_count
        FROM c2c_amount_reservations reservation_row
        WHERE reservation_row.payment_intent_id = OLD.id
          AND EXISTS (
              SELECT 1
              FROM payment_intent_state_histories history_row
              WHERE history_row.payment_intent_id = OLD.id
                AND history_row.from_state = 'awaiting_user_action'
                AND history_row.to_state = 'expired'
                AND history_row.reason_code = 'c2c_late_review_expired'
          )
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.source_quote_id = OLD.source_quote_id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM orders order_row
              WHERE order_row.source_quote_id = OLD.source_quote_id
                AND (order_row.state <> 'awaiting_payment'
                     OR order_row.purchase_settlement_id IS NOT NULL
                     OR order_row.payment_intent_id IS NOT NULL)
          )
          AND NOT EXISTS (
              SELECT 1
              FROM promotion_usage_reservations promotion_row
              INNER JOIN promotion_usage_releases release_row
                ON release_row.promotion_usage_reservation_id = promotion_row.id
              WHERE promotion_row.quote_id = OLD.source_quote_id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM promotion_usage_reservations promotion_row
              INNER JOIN promotion_usage_redemptions redemption_row
                ON redemption_row.promotion_usage_reservation_id = promotion_row.id
              WHERE promotion_row.quote_id = OLD.source_quote_id
          )
          AND (
              EXISTS (
                  SELECT 1
                  FROM c2c_bank_transactions bank_row
                  WHERE bank_row.c2c_destination_account_id = reservation_row.c2c_destination_account_id
                    AND bank_row.amount_irr = reservation_row.payable_amount_irr
                    AND bank_row.status IN ('pending','settled')
                    AND bank_row.occurred_at >= reservation_row.reserved_at
                    AND bank_row.occurred_at <= reservation_row.late_review_until
              )
              OR EXISTS (
                  SELECT 1
                  FROM c2c_manual_submissions submission_row
                  WHERE submission_row.payment_intent_id = OLD.id
                    AND submission_row.c2c_amount_reservation_id = reservation_row.id
                    AND submission_row.c2c_destination_account_id = reservation_row.c2c_destination_account_id
                    AND submission_row.claimed_amount_irr = reservation_row.payable_amount_irr
                    AND submission_row.claimed_paid_at >= reservation_row.reserved_at
                    AND submission_row.claimed_paid_at <= reservation_row.late_review_until
              )
          );
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' AND valid_wallet_cancel_count = 1) OR
        (OLD.state = 'expired' AND NEW.state = 'submitted' AND valid_c2c_evidence_recovery_count = 1) OR
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR
        (OLD.state = 'authorized' AND NEW.state = 'captured') OR
        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR
        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR
        (OLD.state = 'partially_refunded' AND NEW.state = 'refund_pending')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent state transition is invalid.';
    END IF;

    IF NEW.purpose = 'purchase' AND OLD.state <> 'captured' AND NEW.state = 'captured' THEN
        SELECT COUNT(*) INTO purchase_settlement_count
        FROM purchase_settlements
        WHERE payment_intent_id = NEW.id;

        IF purchase_settlement_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase capture requires one authoritative purchase settlement.';
        END IF;
    END IF;

    IF NEW.purpose = 'purchase' AND NEW.state = 'refund_pending' AND OLD.state IN ('captured','partially_refunded') THEN
        IF NEW.latest_purchase_refund_id IS NULL
           OR (OLD.latest_purchase_refund_id IS NOT NULL AND NEW.latest_purchase_refund_id = OLD.latest_purchase_refund_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund pending transition requires a new authoritative refund pointer.';
        END IF;

        SELECT COUNT(*), MAX(refund_row.resulting_payment_state)
        INTO valid_refund_pointer_count, pointed_result_state
        FROM purchase_refunds refund_row
        INNER JOIN purchase_settlements settlement_row ON settlement_row.id = refund_row.purchase_settlement_id
        WHERE refund_row.id = NEW.latest_purchase_refund_id
          AND refund_row.payment_intent_id = NEW.id
          AND refund_row.user_id = NEW.user_id
          AND settlement_row.payment_intent_id = NEW.id;

        IF valid_refund_pointer_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund pending transition requires a matching authoritative refund record.';
        END IF;
    END IF;

    IF NEW.purpose = 'purchase' AND OLD.state = 'refund_pending' AND NEW.state IN ('partially_refunded','refunded') THEN
        IF NEW.latest_purchase_refund_id IS NULL OR NOT (NEW.latest_purchase_refund_id <=> OLD.latest_purchase_refund_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund completion must preserve its authoritative refund pointer.';
        END IF;

        SELECT COUNT(*), MAX(resulting_payment_state)
        INTO valid_refund_pointer_count, pointed_result_state
        FROM purchase_refunds
        WHERE id = NEW.latest_purchase_refund_id
          AND payment_intent_id = NEW.id;

        IF valid_refund_pointer_count <> 1 OR pointed_result_state <> NEW.state THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund completion state must match its authoritative refund record.';
        END IF;
    END IF;

    IF NEW.purpose <> 'purchase' AND NEW.latest_purchase_refund_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-purchase payment intent cannot carry purchase refund authority.';
    END IF;

    IF NEW.purpose = 'purchase'
       AND NEW.state IN ('refund_pending','partially_refunded','refunded')
       AND NEW.latest_purchase_refund_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund lifecycle requires authoritative refund linkage.';
    END IF;

    IF NEW.purpose = 'purchase'
       AND NEW.state NOT IN ('refund_pending','partially_refunded','refunded')
       AND NEW.latest_purchase_refund_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-refund purchase state cannot carry purchase refund authority.';
    END IF;

    IF NEW.state IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured payment lifecycle requires captured_at.';
    END IF;
    IF NEW.state NOT IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-capture payment intent cannot carry captured_at.';
    END IF;
    IF OLD.captured_at IS NOT NULL AND NOT (NEW.captured_at <=> OLD.captured_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent capture timestamp is immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_manual_submissions_insert_guard
BEFORE INSERT ON c2c_manual_submissions
FOR EACH ROW
BEGIN
    DECLARE valid_submission_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_submission_count
    FROM c2c_amount_reservations reservation_row
    INNER JOIN payment_intents intent_row ON intent_row.id = reservation_row.payment_intent_id
    WHERE reservation_row.id = NEW.c2c_amount_reservation_id
      AND reservation_row.payment_intent_id = NEW.payment_intent_id
      AND reservation_row.c2c_destination_account_id = NEW.c2c_destination_account_id
      AND reservation_row.payable_amount_irr = NEW.claimed_amount_irr
      AND reservation_row.reserved_at <= NEW.claimed_paid_at
      AND reservation_row.late_review_until >= NEW.claimed_paid_at
      AND intent_row.user_id = NEW.submitted_by_user_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'card_to_card'
      AND intent_row.provider_code = 'card_to_card'
      AND intent_row.captured_at IS NULL
      AND (
          intent_row.state = 'awaiting_user_action'
          OR (
              intent_row.state = 'expired'
              AND COALESCE(IS_USED_LOCK('freedom:c2c-evidence-recovery:v1'), -1) = CONNECTION_ID()
              AND EXISTS (
                  SELECT 1
                  FROM payment_intent_state_histories history_row
                  WHERE history_row.payment_intent_id = intent_row.id
                    AND history_row.from_state = 'awaiting_user_action'
                    AND history_row.to_state = 'expired'
                    AND history_row.reason_code = 'c2c_late_review_expired'
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM purchase_settlements settlement_row
                  WHERE settlement_row.source_quote_id = intent_row.source_quote_id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM orders order_row
                  WHERE order_row.source_quote_id = intent_row.source_quote_id
                    AND (order_row.state <> 'awaiting_payment'
                         OR order_row.purchase_settlement_id IS NOT NULL
                         OR order_row.payment_intent_id IS NOT NULL)
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM promotion_usage_reservations promotion_row
                  INNER JOIN promotion_usage_releases release_row
                    ON release_row.promotion_usage_reservation_id = promotion_row.id
                  WHERE promotion_row.quote_id = intent_row.source_quote_id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM promotion_usage_reservations promotion_row
                  INNER JOIN promotion_usage_redemptions redemption_row
                    ON redemption_row.promotion_usage_reservation_id = promotion_row.id
                  WHERE promotion_row.quote_id = intent_row.source_quote_id
              )
          )
      );
    IF valid_submission_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C manual submission must match one owned purchase reservation and exact payable amount.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('C2C concurrent-evidence recovery guard migration is intentionally forward-only.');
    }
};
