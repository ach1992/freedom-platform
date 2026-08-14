<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-002 PAY-003 WAL-004 DAT-002 DAT-003 QUA-004 */
    public function up(): void
    {
        Schema::create('purchase_refunds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('refund_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('purchase_settlement_id')->constrained('purchase_settlements')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('provider_event_row_id')->unique()->constrained('payment_provider_events')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_refund_id', 191);
            $table->char('evidence_payload_hash', 64);
            $table->bigInteger('amount_irr');
            $table->bigInteger('cumulative_refunded_irr');
            $table->char('currency', 3);
            $table->string('resulting_payment_state', 32);
            $table->dateTime('refunded_at', 6);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['provider_code', 'provider_refund_id'], 'purchase_refund_provider_unique');
            $table->index(['purchase_settlement_id', 'id'], 'purchase_refund_settlement_id_idx');
            $table->index(['payment_intent_id', 'id'], 'purchase_refund_intent_id_idx');
        });

        DB::statement('ALTER TABLE purchase_refunds ADD CONSTRAINT purchase_refund_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64 AND CHAR_LENGTH(`evidence_payload_hash`) = 64)');
        DB::statement('ALTER TABLE purchase_refunds ADD CONSTRAINT purchase_refund_amount_chk CHECK (`amount_irr` > 0 AND `cumulative_refunded_irr` >= `amount_irr`)');
        DB::statement("ALTER TABLE purchase_refunds ADD CONSTRAINT purchase_refund_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement("ALTER TABLE purchase_refunds ADD CONSTRAINT purchase_refund_result_state_chk CHECK (`resulting_payment_state` IN ('partially_refunded','refunded'))");

        DB::statement('ALTER TABLE payment_intents ADD COLUMN latest_purchase_refund_id BIGINT UNSIGNED NULL AFTER captured_at');
        DB::statement('ALTER TABLE payment_intents ADD INDEX payment_intent_latest_purchase_refund_idx (latest_purchase_refund_id)');
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_latest_purchase_refund_fk FOREIGN KEY (latest_purchase_refund_id) REFERENCES purchase_refunds(id) ON DELETE RESTRICT');
        DB::statement(<<<'SQL'
ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_purchase_refund_pointer_chk CHECK (
    `purpose` <> 'purchase'
    OR (`state` IN ('refund_pending','partially_refunded','refunded') AND `latest_purchase_refund_id` IS NOT NULL)
    OR (`state` NOT IN ('refund_pending','partially_refunded','refunded') AND `latest_purchase_refund_id` IS NULL)
)
SQL);

        $this->createPurchaseRefundGuards();
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        $this->createRefundAwarePaymentIntentUpdateGuard();
    }

    public function down(): void
    {
        if (DB::table('purchase_refunds')->exists()) {
            throw new RuntimeException('Cannot roll back purchase refund authority while purchase refunds exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_insert_guard');

        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT payment_intent_purchase_refund_pointer_chk');
        DB::statement('ALTER TABLE payment_intents DROP FOREIGN KEY payment_intent_latest_purchase_refund_fk');
        DB::statement('ALTER TABLE payment_intents DROP INDEX payment_intent_latest_purchase_refund_idx');
        DB::statement('ALTER TABLE payment_intents DROP COLUMN latest_purchase_refund_id');
        Schema::dropIfExists('purchase_refunds');

        $this->createSettlementAwarePaymentIntentUpdateGuard();
    }

    private function createPurchaseRefundGuards(): void
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_refunds_update_guard
BEFORE UPDATE ON purchase_refunds
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refunds are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_refunds_delete_guard
BEFORE DELETE ON purchase_refunds
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refunds are non-deletable.';
END
SQL);
    }

    private function createRefundAwarePaymentIntentUpdateGuard(): void
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
};
