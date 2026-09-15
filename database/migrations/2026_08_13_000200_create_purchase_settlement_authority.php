<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-002 PAY-003 PAY-004 PAY-005 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        Schema::create('purchase_settlements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('provider_transaction_row_id')->unique()->constrained('payment_provider_transactions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_quote_id')->constrained('quotes')->restrictOnDelete();
            $table->char('source_quote_public_id', 26);
            $table->string('provider_code', 64);
            $table->string('provider_transaction_id', 191);
            $table->char('evidence_payload_hash', 64);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->dateTime('settled_at', 6);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at'], 'purchase_settlement_user_created_idx');
            $table->index(['source_quote_id', 'created_at'], 'purchase_settlement_quote_created_idx');
            $table->unique(['provider_code', 'provider_transaction_id'], 'purchase_settlement_provider_tx_unique');
        });

        DB::statement('ALTER TABLE purchase_settlements ADD CONSTRAINT purchase_settlement_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement("ALTER TABLE purchase_settlements ADD CONSTRAINT purchase_settlement_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement('ALTER TABLE purchase_settlements ADD CONSTRAINT purchase_settlement_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');

        $this->createSettlementGuards();
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createSettlementAwarePaymentIntentUpdateGuard();
    }

    public function down(): void
    {
        if (DB::table('purchase_settlements')->exists()) {
            throw new RuntimeException('Cannot roll back purchase settlement authority while purchase settlements exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_insert_guard');
        Schema::dropIfExists('purchase_settlements');
        $this->createPriorPaymentIntentUpdateGuard();
    }

    private function createSettlementGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_settlements_insert_guard
BEFORE INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    DECLARE valid_intent_count INT DEFAULT 0;
    DECLARE valid_provider_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.wallet_account_id IS NULL
      AND intent_row.user_id = NEW.user_id
      AND intent_row.source_quote_id = NEW.source_quote_id
      AND intent_row.source_quote_public_id = NEW.source_quote_public_id
      AND intent_row.provider_code = NEW.provider_code
      AND intent_row.amount_irr = NEW.amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.state IN ('submitted','verifying','pending_manual_review','authorized')
      AND intent_row.captured_at IS NULL;

    IF valid_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase settlement requires one matching pre-capture purchase intent.';
    END IF;

    SELECT COUNT(*) INTO valid_provider_count
    FROM payment_provider_transactions provider_transaction
    WHERE provider_transaction.id = NEW.provider_transaction_row_id
      AND provider_transaction.payment_intent_id = NEW.payment_intent_id
      AND provider_transaction.provider_code = NEW.provider_code
      AND provider_transaction.provider_transaction_id = NEW.provider_transaction_id
      AND provider_transaction.evidence_payload_hash = NEW.evidence_payload_hash
      AND provider_transaction.transaction_status = 'settled'
      AND provider_transaction.amount_irr = NEW.amount_irr
      AND provider_transaction.currency = NEW.currency
      AND provider_transaction.settled_at = NEW.settled_at;

    IF valid_provider_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase settlement requires one matching authoritative provider transaction.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_settlements_update_guard
BEFORE UPDATE ON purchase_settlements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase settlements are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_settlements_delete_guard
BEFORE DELETE ON purchase_settlements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase settlements are non-deletable.';
END
SQL);
    }

    private function createSettlementAwarePaymentIntentUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_settlement_count INT DEFAULT 0;

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

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR
        (OLD.state = 'authorized' AND NEW.state = 'captured') OR
        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR
        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR
        (OLD.state = 'partially_refunded' AND NEW.state IN ('refund_pending','refunded'))
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

    private function createPriorPaymentIntentUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
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

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR
        (OLD.state = 'authorized' AND NEW.state = 'captured') OR
        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR
        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR
        (OLD.state = 'partially_refunded' AND NEW.state IN ('refund_pending','refunded'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent state transition is invalid.';
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
