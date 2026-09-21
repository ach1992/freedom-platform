<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keep an exact provider-finished contradiction fail-closed in canonical Payments
     * authority instead of leaving the purchase represented as safely failed/expired.
     */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_authority_update_guard');
        $this->createConflictAwareNowPaymentsAuthorityGuard();

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createConflictAwarePaymentIntentGuard();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createPriorPaymentIntentGuard();

        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_authority_update_guard');
        $this->createPriorNowPaymentsAuthorityGuard();
    }

    private function createConflictAwareNowPaymentsAuthorityGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_authority_update_guard
BEFORE UPDATE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.request_key <=> OLD.request_key)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.order_id <=> OLD.order_id)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.rate_source <=> OLD.rate_source)
       OR NOT (NEW.rate_irr <=> OLD.rate_irr)
       OR NOT (NEW.rate_fetched_at <=> OLD.rate_fetched_at)
       OR NOT (NEW.rate_response_hash <=> OLD.rate_response_hash)
       OR NOT (NEW.pricing_policy_code <=> OLD.pricing_policy_code)
       OR NOT (NEW.price_amount_usd <=> OLD.price_amount_usd)
       OR NOT (NEW.price_currency <=> OLD.price_currency)
       OR NOT (NEW.pay_currency <=> OLD.pay_currency)
       OR NOT (NEW.callback_url <=> OLD.callback_url)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash)
       OR NOT (NEW.create_attempted_at <=> OLD.create_attempted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments pricing/request authority is immutable.';
    END IF;

    IF OLD.provider_payment_id IS NOT NULL AND NOT (NEW.provider_payment_id <=> OLD.provider_payment_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider payment ID is immutable once accepted.';
    END IF;
    IF OLD.create_response_hash IS NOT NULL AND NOT (NEW.create_response_hash <=> OLD.create_response_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments create response identity is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_amount IS NOT NULL AND NOT (NEW.provider_pay_amount <=> OLD.provider_pay_amount) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay amount is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_address IS NOT NULL AND NOT (NEW.provider_pay_address <=> OLD.provider_pay_address) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay address is immutable once accepted.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'initiating' AND NEW.state IN ('created','uncertain','failed')) OR
        (OLD.state = 'uncertain' AND NEW.state = 'manual_review') OR
        (OLD.state = 'created' AND NEW.state IN ('manual_review','finished','failed','expired')) OR
        (OLD.state = 'manual_review' AND NEW.state IN ('finished','failed','expired')) OR
        (OLD.state IN ('failed','expired') AND NEW.state = 'manual_review'
         AND NEW.provider_payment_id IS NOT NULL AND NEW.provider_status = 'finished')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority state transition is invalid.';
    END IF;
END
SQL);
    }

    private function createPriorNowPaymentsAuthorityGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_authority_update_guard
BEFORE UPDATE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.request_key <=> OLD.request_key)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.order_id <=> OLD.order_id)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.rate_source <=> OLD.rate_source)
       OR NOT (NEW.rate_irr <=> OLD.rate_irr)
       OR NOT (NEW.rate_fetched_at <=> OLD.rate_fetched_at)
       OR NOT (NEW.rate_response_hash <=> OLD.rate_response_hash)
       OR NOT (NEW.pricing_policy_code <=> OLD.pricing_policy_code)
       OR NOT (NEW.price_amount_usd <=> OLD.price_amount_usd)
       OR NOT (NEW.price_currency <=> OLD.price_currency)
       OR NOT (NEW.pay_currency <=> OLD.pay_currency)
       OR NOT (NEW.callback_url <=> OLD.callback_url)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash)
       OR NOT (NEW.create_attempted_at <=> OLD.create_attempted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments pricing/request authority is immutable.';
    END IF;

    IF OLD.provider_payment_id IS NOT NULL AND NOT (NEW.provider_payment_id <=> OLD.provider_payment_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider payment ID is immutable once accepted.';
    END IF;
    IF OLD.create_response_hash IS NOT NULL AND NOT (NEW.create_response_hash <=> OLD.create_response_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments create response identity is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_amount IS NOT NULL AND NOT (NEW.provider_pay_amount <=> OLD.provider_pay_amount) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay amount is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_address IS NOT NULL AND NOT (NEW.provider_pay_address <=> OLD.provider_pay_address) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay address is immutable once accepted.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'initiating' AND NEW.state IN ('created','uncertain','failed')) OR
        (OLD.state = 'uncertain' AND NEW.state = 'manual_review') OR
        (OLD.state = 'created' AND NEW.state IN ('manual_review','finished','failed','expired')) OR
        (OLD.state = 'manual_review' AND NEW.state IN ('finished','failed','expired'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority state transition is invalid.';
    END IF;
END
SQL);
    }

    private function createConflictAwarePaymentIntentGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_settlement_count INT DEFAULT 0;
    DECLARE valid_refund_pointer_count INT DEFAULT 0;
    DECLARE pointed_result_state VARCHAR(32) DEFAULT NULL;
    DECLARE valid_wallet_cancel_count INT DEFAULT 0;
    DECLARE nowpayments_finished_conflict_count INT DEFAULT 0;

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

    IF OLD.state IN ('failed','expired') AND NEW.state = 'pending_manual_review' THEN
        SELECT COUNT(*) INTO nowpayments_finished_conflict_count
        FROM nowpayments_payment_authorities authority_row
        WHERE authority_row.payment_intent_id = OLD.id
          AND OLD.purpose = 'purchase'
          AND OLD.provider_code = 'nowpayments'
          AND OLD.payment_method_code = 'nowpayments'
          AND authority_row.state = 'manual_review'
          AND authority_row.provider_payment_id IS NOT NULL
          AND authority_row.provider_status = 'finished'
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = OLD.id
          );

        IF nowpayments_finished_conflict_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal NOWPayments intent can reopen only for one exact finished-provider reconciliation conflict.';
        END IF;
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' AND valid_wallet_cancel_count = 1) OR
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR
        (OLD.state = 'authorized' AND NEW.state = 'captured') OR
        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR
        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR
        (OLD.state = 'partially_refunded' AND NEW.state = 'refund_pending') OR
        (OLD.state IN ('failed','expired') AND NEW.state = 'pending_manual_review' AND nowpayments_finished_conflict_count = 1)
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
    }

    private function createPriorPaymentIntentGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_settlement_count INT DEFAULT 0;
    DECLARE valid_refund_pointer_count INT DEFAULT 0;
    DECLARE pointed_result_state VARCHAR(32) DEFAULT NULL;
    DECLARE valid_wallet_cancel_count INT DEFAULT 0;

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

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' AND valid_wallet_cancel_count = 1) OR
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
    }
};
