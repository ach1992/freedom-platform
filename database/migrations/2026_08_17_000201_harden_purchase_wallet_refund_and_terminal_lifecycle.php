<?php

declare(strict_types=1);

use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-002 PAY-003 WAL-002 WAL-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_refunds_insert_guard');
        $this->createHardenedWalletRefundGuard();

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createWalletTerminalAwarePaymentIntentUpdateGuard();
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_wallet_reservations') && DB::table('purchase_wallet_reservations')->exists()) {
            throw new RuntimeException('Cannot roll back wallet purchase terminal/refund hardening after reservation facts exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createCanonicalPaymentIntentUpdateGuard();

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_refunds_insert_guard');
        $this->createLegacyWalletRefundGuard();
    }

    private function createHardenedWalletRefundGuard(): void
    {
        $purchaseOffsetCode = WalletSystemAccountCode::PURCHASE_CLEARING;
        DB::unprepared(<<<SQL
CREATE TRIGGER purchase_wallet_refunds_insert_guard
BEFORE INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_refund_count INT DEFAULT 0;

    IF NEW.provider_code = 'wallet' THEN
        SELECT COUNT(*) INTO valid_wallet_refund_count
        FROM purchase_wallet_reservations reservation_row
        INNER JOIN payment_intents intent_row
          ON intent_row.id = reservation_row.payment_intent_id
        INNER JOIN purchase_settlements settlement_row
          ON settlement_row.id = NEW.purchase_settlement_id
         AND settlement_row.payment_intent_id = reservation_row.payment_intent_id
        INNER JOIN wallet_holds hold_row
          ON hold_row.id = reservation_row.wallet_hold_id
        INNER JOIN ledger_accounts offset_row
          ON offset_row.code = '{$purchaseOffsetCode}'
        INNER JOIN ledger_transactions refund_ledger_row
          ON SHA2(CONCAT('wallet', CHAR(0), 'refund-ledger:', refund_ledger_row.id), 256) = NEW.provider_refund_id
        WHERE reservation_row.payment_intent_id = NEW.payment_intent_id
          AND settlement_row.provider_code = 'wallet'
          AND settlement_row.currency = 'IRR'
          AND intent_row.provider_code = 'wallet'
          AND intent_row.payment_method_code = 'wallet'
          AND intent_row.wallet_account_id IS NULL
          AND intent_row.user_id = NEW.user_id
          AND intent_row.currency = 'IRR'
          AND reservation_row.wallet_account_id = hold_row.ledger_account_id
          AND hold_row.source_type = 'payment_intent'
          AND hold_row.source_id = intent_row.public_id
          AND hold_row.status = 'captured'
          AND hold_row.captured_ledger_transaction_id IS NOT NULL
          AND offset_row.owner_user_id IS NULL
          AND offset_row.wallet_bucket IS NULL
          AND offset_row.account_class = 'revenue'
          AND offset_row.currency = 'IRR'
          AND offset_row.is_active = 1
          AND refund_ledger_row.finalized_at IS NOT NULL
          AND refund_ledger_row.transaction_type = 'wallet_purchase_refund'
          AND refund_ledger_row.source_type = 'purchase_refund'
          AND refund_ledger_row.source_id = SHA2(CONCAT(
              'wallet-purchase-refund', CHAR(0),
              NEW.refund_key, CHAR(0),
              'settlement:', NEW.purchase_settlement_id, CHAR(0),
              'intent:', NEW.payment_intent_id, CHAR(0),
              'hold:', hold_row.id, CHAR(0),
              'capture-ledger:', hold_row.captured_ledger_transaction_id
          ), 256)
          AND refund_ledger_row.finalized_at = NEW.refunded_at
          AND refund_ledger_row.expected_total_irr = NEW.amount_irr
          AND refund_ledger_row.posted_debit_irr = NEW.amount_irr
          AND refund_ledger_row.posted_credit_irr = NEW.amount_irr
          AND refund_ledger_row.entry_count = 2
          AND (
              SELECT COUNT(*)
              FROM ledger_entries debit_row
              WHERE debit_row.ledger_transaction_id = refund_ledger_row.id
                AND debit_row.ledger_account_id = offset_row.id
                AND debit_row.direction = 'debit'
                AND debit_row.amount_irr = NEW.amount_irr
          ) = 1
          AND (
              SELECT COUNT(*)
              FROM ledger_entries credit_row
              WHERE credit_row.ledger_transaction_id = refund_ledger_row.id
                AND credit_row.ledger_account_id = reservation_row.wallet_account_id
                AND credit_row.direction = 'credit'
                AND credit_row.amount_irr = NEW.amount_irr
          ) = 1;

        IF valid_wallet_refund_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase refund requires one exact settlement-bound finalized ledger reversal.';
        END IF;
    END IF;
END
SQL);
    }

    private function createLegacyWalletRefundGuard(): void
    {
        $purchaseOffsetCode = WalletSystemAccountCode::PURCHASE_CLEARING;
        DB::unprepared(<<<SQL
CREATE TRIGGER purchase_wallet_refunds_insert_guard
BEFORE INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_refund_count INT DEFAULT 0;

    IF NEW.provider_code = 'wallet' THEN
        SELECT COUNT(*) INTO valid_wallet_refund_count
        FROM purchase_wallet_reservations reservation_row
        INNER JOIN payment_intents intent_row
          ON intent_row.id = reservation_row.payment_intent_id
        INNER JOIN purchase_settlements settlement_row
          ON settlement_row.id = NEW.purchase_settlement_id
         AND settlement_row.payment_intent_id = reservation_row.payment_intent_id
        INNER JOIN wallet_holds hold_row
          ON hold_row.id = reservation_row.wallet_hold_id
        INNER JOIN ledger_accounts offset_row
          ON offset_row.code = '{$purchaseOffsetCode}'
        INNER JOIN ledger_transactions refund_ledger_row
          ON SHA2(CONCAT('wallet', CHAR(0), 'refund-ledger:', refund_ledger_row.id), 256) = NEW.provider_refund_id
        WHERE reservation_row.payment_intent_id = NEW.payment_intent_id
          AND settlement_row.provider_code = 'wallet'
          AND settlement_row.currency = 'IRR'
          AND intent_row.provider_code = 'wallet'
          AND intent_row.payment_method_code = 'wallet'
          AND intent_row.wallet_account_id IS NULL
          AND intent_row.user_id = NEW.user_id
          AND intent_row.currency = 'IRR'
          AND reservation_row.wallet_account_id = hold_row.ledger_account_id
          AND hold_row.source_type = 'payment_intent'
          AND hold_row.source_id = intent_row.public_id
          AND hold_row.status = 'captured'
          AND hold_row.captured_ledger_transaction_id IS NOT NULL
          AND offset_row.owner_user_id IS NULL
          AND offset_row.wallet_bucket IS NULL
          AND offset_row.account_class = 'revenue'
          AND offset_row.currency = 'IRR'
          AND offset_row.is_active = 1
          AND refund_ledger_row.finalized_at IS NOT NULL
          AND refund_ledger_row.transaction_type = 'wallet_purchase_refund'
          AND refund_ledger_row.source_type = 'purchase_refund'
          AND refund_ledger_row.source_id = NEW.refund_key
          AND refund_ledger_row.finalized_at = NEW.refunded_at
          AND refund_ledger_row.expected_total_irr = NEW.amount_irr
          AND refund_ledger_row.posted_debit_irr = NEW.amount_irr
          AND refund_ledger_row.posted_credit_irr = NEW.amount_irr
          AND refund_ledger_row.entry_count = 2
          AND (
              SELECT COUNT(*)
              FROM ledger_entries debit_row
              WHERE debit_row.ledger_transaction_id = refund_ledger_row.id
                AND debit_row.ledger_account_id = offset_row.id
                AND debit_row.direction = 'debit'
                AND debit_row.amount_irr = NEW.amount_irr
          ) = 1
          AND (
              SELECT COUNT(*)
              FROM ledger_entries credit_row
              WHERE credit_row.ledger_transaction_id = refund_ledger_row.id
                AND credit_row.ledger_account_id = reservation_row.wallet_account_id
                AND credit_row.direction = 'credit'
                AND credit_row.amount_irr = NEW.amount_irr
          ) = 1;

        IF valid_wallet_refund_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase refund requires one exact finalized ledger reversal into the reserved wallet.';
        END IF;
    END IF;
END
SQL);
    }

    private function createWalletTerminalAwarePaymentIntentUpdateGuard(): void
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

    private function createCanonicalPaymentIntentUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_settlement_count INT DEFAULT 0;
    DECLARE valid_refund_pointer_count INT DEFAULT 0;
    DECLARE pointed_result_state VARCHAR(32) DEFAULT NULL;

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

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
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
