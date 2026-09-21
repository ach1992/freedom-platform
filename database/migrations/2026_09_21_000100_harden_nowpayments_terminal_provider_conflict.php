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

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_insert_guard');
        $this->createConflictAwarePaymentIntentInsertGuard();

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createConflictAwarePaymentIntentGuard();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createPriorPaymentIntentGuard();

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_insert_guard');
        $this->createPriorPaymentIntentInsertGuard();

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
    DECLARE finished_status_observation_count INT DEFAULT 0;
    DECLARE terminal_finished_finding_count INT DEFAULT 0;

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

    IF OLD.state IN ('failed','expired') AND NEW.state = 'manual_review' THEN
        SELECT COUNT(*) INTO finished_status_observation_count
        FROM nowpayments_payment_observations observation_row
        WHERE observation_row.nowpayments_payment_authority_id = OLD.id
          AND observation_row.event_type = 'status_lookup'
          AND observation_row.provider_payment_id = OLD.provider_payment_id
          AND observation_row.provider_status = 'finished';

        SELECT COUNT(*) INTO terminal_finished_finding_count
        FROM nowpayments_reconciliation_findings finding_row
        WHERE finding_row.nowpayments_payment_authority_id = OLD.id
          AND finding_row.code = 'terminal_local_state_conflicts_with_finished_provider'
          AND finding_row.severity = 'critical'
          AND finding_row.provider_status = 'finished';

        IF finished_status_observation_count < 1 OR terminal_finished_finding_count < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal NOWPayments provider-finished recovery requires durable finished-status reconciliation evidence.';
        END IF;
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'initiating' AND NEW.state IN ('created','uncertain','failed')) OR
        (OLD.state = 'uncertain' AND NEW.state = 'manual_review') OR
        (OLD.state = 'created' AND NEW.state IN ('manual_review','finished','failed','expired')) OR
        (OLD.state = 'manual_review' AND NEW.state IN ('finished','failed','expired')) OR
        (OLD.state IN ('failed','expired') AND NEW.state = 'manual_review'
         AND NEW.provider_payment_id IS NOT NULL AND NEW.provider_status = 'finished'
         AND finished_status_observation_count >= 1 AND terminal_finished_finding_count >= 1)
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

    private function createConflictAwarePaymentIntentInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_insert_guard
BEFORE INSERT ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_count INT DEFAULT 0;
    DECLARE valid_quote_count INT DEFAULT 0;
    DECLARE valid_decision_count INT DEFAULT 0;
    DECLARE valid_method_count INT DEFAULT 0;
    DECLARE unresolved_purchase_manual_review_count INT DEFAULT 0;

    IF NEW.purpose = 'wallet_top_up' THEN
        IF NEW.source_quote_id IS NOT NULL OR NEW.payment_eligibility_decision_id IS NOT NULL OR NEW.payment_method_version_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent cannot carry purchase authority bindings.';
        END IF;
        SELECT COUNT(*) INTO valid_wallet_count FROM ledger_accounts
        WHERE id = NEW.wallet_account_id AND owner_user_id = NEW.user_id AND account_class = 'liability'
          AND wallet_bucket = 'cash' AND currency = 'IRR' AND is_active = 1;
        IF valid_wallet_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.'; END IF;
    ELSEIF NEW.purpose = 'purchase' THEN
        IF NEW.wallet_account_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent cannot carry a wallet top-up binding.'; END IF;

        SELECT COUNT(*) INTO unresolved_purchase_manual_review_count
        FROM payment_intents existing_intent
        WHERE existing_intent.purpose = 'purchase'
          AND existing_intent.source_quote_id = NEW.source_quote_id
          AND existing_intent.user_id = NEW.user_id
          AND existing_intent.state = 'pending_manual_review';
        IF unresolved_purchase_manual_review_count <> 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent is locked by unresolved payment reconciliation.';
        END IF;

        SELECT COUNT(*) INTO valid_quote_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id AND quote_row.final_price_irr = NEW.amount_irr
          AND quote_row.currency = NEW.currency AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.valid_from <= NEW.created_at AND quote_row.expires_at > NEW.created_at;
        IF valid_quote_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one current matching immutable Quote.'; END IF;

        SELECT COUNT(*) INTO valid_decision_count
        FROM payment_method_eligibility_decisions decision_row
        INNER JOIN quotes quote_row ON quote_row.id = decision_row.source_quote_id
        WHERE decision_row.id = NEW.payment_eligibility_decision_id
          AND decision_row.public_id = NEW.payment_eligibility_decision_public_id
          AND decision_row.source_quote_id = NEW.source_quote_id
          AND decision_row.source_quote_public_id = NEW.source_quote_public_id
          AND decision_row.user_id = NEW.user_id
          AND decision_row.action_snapshot = quote_row.action_snapshot
          AND decision_row.currency_snapshot = NEW.currency
          AND decision_row.amount_irr_snapshot = NEW.amount_irr
          AND decision_row.configuration_snapshot_hash = NEW.payment_eligibility_configuration_hash
          AND decision_row.created_at <= NEW.created_at;
        IF valid_decision_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one matching PAY-001 eligibility decision.'; END IF;

        SELECT COUNT(*) INTO valid_method_count
        FROM payment_method_eligibility_decision_methods decision_method
        INNER JOIN payment_method_versions method_version ON method_version.id = decision_method.payment_method_version_id
        WHERE decision_method.payment_method_eligibility_decision_id = NEW.payment_eligibility_decision_id
          AND decision_method.payment_method_version_id = NEW.payment_method_version_id
          AND decision_method.method_code = NEW.payment_method_code
          AND decision_method.method_version = NEW.payment_method_version
          AND decision_method.route_order IS NOT NULL
          AND decision_method.reason_code = 'eligible'
          AND decision_method.configuration_snapshot_hash = NEW.payment_eligibility_method_configuration_hash
          AND method_version.method_code = NEW.payment_method_code
          AND method_version.version = NEW.payment_method_version
          AND method_version.configuration_snapshot_hash = NEW.payment_method_configuration_hash
          AND NEW.provider_code = NEW.payment_method_code;
        IF valid_method_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one selected PAY-001 payment method version.'; END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent purpose is unsupported.';
    END IF;
END
SQL);
    }

    private function createPriorPaymentIntentInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_insert_guard
BEFORE INSERT ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_count INT DEFAULT 0;
    DECLARE valid_quote_count INT DEFAULT 0;
    DECLARE valid_decision_count INT DEFAULT 0;
    DECLARE valid_method_count INT DEFAULT 0;

    IF NEW.purpose = 'wallet_top_up' THEN
        IF NEW.source_quote_id IS NOT NULL OR NEW.payment_eligibility_decision_id IS NOT NULL OR NEW.payment_method_version_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent cannot carry purchase authority bindings.';
        END IF;
        SELECT COUNT(*) INTO valid_wallet_count FROM ledger_accounts
        WHERE id = NEW.wallet_account_id AND owner_user_id = NEW.user_id AND account_class = 'liability'
          AND wallet_bucket = 'cash' AND currency = 'IRR' AND is_active = 1;
        IF valid_wallet_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.'; END IF;
    ELSEIF NEW.purpose = 'purchase' THEN
        IF NEW.wallet_account_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent cannot carry a wallet top-up binding.'; END IF;
        SELECT COUNT(*) INTO valid_quote_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id AND quote_row.final_price_irr = NEW.amount_irr
          AND quote_row.currency = NEW.currency AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.valid_from <= NEW.created_at AND quote_row.expires_at > NEW.created_at;
        IF valid_quote_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one current matching immutable Quote.'; END IF;

        SELECT COUNT(*) INTO valid_decision_count
        FROM payment_method_eligibility_decisions decision_row
        INNER JOIN quotes quote_row ON quote_row.id = decision_row.source_quote_id
        WHERE decision_row.id = NEW.payment_eligibility_decision_id
          AND decision_row.public_id = NEW.payment_eligibility_decision_public_id
          AND decision_row.source_quote_id = NEW.source_quote_id
          AND decision_row.source_quote_public_id = NEW.source_quote_public_id
          AND decision_row.user_id = NEW.user_id
          AND decision_row.action_snapshot = quote_row.action_snapshot
          AND decision_row.currency_snapshot = NEW.currency
          AND decision_row.amount_irr_snapshot = NEW.amount_irr
          AND decision_row.configuration_snapshot_hash = NEW.payment_eligibility_configuration_hash
          AND decision_row.created_at <= NEW.created_at;
        IF valid_decision_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one matching PAY-001 eligibility decision.'; END IF;

        SELECT COUNT(*) INTO valid_method_count
        FROM payment_method_eligibility_decision_methods decision_method
        INNER JOIN payment_method_versions method_version ON method_version.id = decision_method.payment_method_version_id
        WHERE decision_method.payment_method_eligibility_decision_id = NEW.payment_eligibility_decision_id
          AND decision_method.payment_method_version_id = NEW.payment_method_version_id
          AND decision_method.method_code = NEW.payment_method_code
          AND decision_method.method_version = NEW.payment_method_version
          AND decision_method.route_order IS NOT NULL
          AND decision_method.reason_code = 'eligible'
          AND decision_method.configuration_snapshot_hash = NEW.payment_eligibility_method_configuration_hash
          AND method_version.method_code = NEW.payment_method_code
          AND method_version.version = NEW.payment_method_version
          AND method_version.configuration_snapshot_hash = NEW.payment_method_configuration_hash
          AND NEW.provider_code = NEW.payment_method_code;
        IF valid_method_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one selected PAY-001 payment method version.'; END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent purpose is unsupported.';
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
          AND EXISTS (
              SELECT 1
              FROM nowpayments_reconciliation_findings finding_row
              WHERE finding_row.nowpayments_payment_authority_id = authority_row.id
                AND finding_row.code = 'terminal_local_state_conflicts_with_finished_provider'
                AND finding_row.severity = 'critical'
                AND finding_row.provider_status = 'finished'
          )
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
