<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-002 PAY-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT payment_intent_purpose_chk');

        DB::statement('ALTER TABLE payment_intents MODIFY wallet_account_id BIGINT UNSIGNED NULL');
        DB::statement(implode(' ', [
            'ALTER TABLE payment_intents',
            'ADD COLUMN source_quote_id BIGINT UNSIGNED NULL AFTER wallet_account_id,',
            'ADD COLUMN source_quote_public_id CHAR(26) NULL AFTER source_quote_id,',
            'ADD COLUMN source_quote_configuration_hash CHAR(64) NULL AFTER source_quote_public_id,',
            'ADD COLUMN payment_eligibility_decision_id BIGINT UNSIGNED NULL AFTER source_quote_configuration_hash,',
            'ADD COLUMN payment_eligibility_decision_public_id CHAR(26) NULL AFTER payment_eligibility_decision_id,',
            'ADD COLUMN payment_eligibility_configuration_hash CHAR(64) NULL AFTER payment_eligibility_decision_public_id,',
            'ADD COLUMN payment_eligibility_method_configuration_hash CHAR(64) NULL AFTER payment_eligibility_configuration_hash,',
            'ADD COLUMN payment_method_version_id BIGINT UNSIGNED NULL AFTER payment_eligibility_method_configuration_hash,',
            'ADD COLUMN payment_method_code VARCHAR(64) NULL AFTER payment_method_version_id,',
            'ADD COLUMN payment_method_version BIGINT UNSIGNED NULL AFTER payment_method_code,',
            'ADD COLUMN payment_method_configuration_hash CHAR(64) NULL AFTER payment_method_version,',
            'ADD INDEX payment_intent_quote_state_idx (source_quote_id, state),',
            'ADD INDEX payment_intent_decision_idx (payment_eligibility_decision_id),',
            'ADD INDEX payment_intent_method_version_idx (payment_method_version_id),',
            'ADD CONSTRAINT payment_intent_source_quote_fk FOREIGN KEY (source_quote_id) REFERENCES quotes(id) ON DELETE RESTRICT,',
            'ADD CONSTRAINT payment_intent_eligibility_decision_fk FOREIGN KEY (payment_eligibility_decision_id) REFERENCES payment_method_eligibility_decisions(id) ON DELETE RESTRICT,',
            'ADD CONSTRAINT payment_intent_method_version_fk FOREIGN KEY (payment_method_version_id) REFERENCES payment_method_versions(id) ON DELETE RESTRICT',
        ]));

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_purpose_chk CHECK (`purpose` IN ('wallet_top_up','purchase'))");
        DB::statement(<<<'SQL'
ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_binding_shape_chk CHECK (
    (`purpose` = 'wallet_top_up'
        AND `wallet_account_id` IS NOT NULL
        AND `source_quote_id` IS NULL
        AND `source_quote_public_id` IS NULL
        AND `source_quote_configuration_hash` IS NULL
        AND `payment_eligibility_decision_id` IS NULL
        AND `payment_eligibility_decision_public_id` IS NULL
        AND `payment_eligibility_configuration_hash` IS NULL
        AND `payment_eligibility_method_configuration_hash` IS NULL
        AND `payment_method_version_id` IS NULL
        AND `payment_method_code` IS NULL
        AND `payment_method_version` IS NULL
        AND `payment_method_configuration_hash` IS NULL)
    OR
    (`purpose` = 'purchase'
        AND `wallet_account_id` IS NULL
        AND `source_quote_id` IS NOT NULL
        AND `source_quote_public_id` IS NOT NULL
        AND `source_quote_configuration_hash` IS NOT NULL
        AND `payment_eligibility_decision_id` IS NOT NULL
        AND `payment_eligibility_decision_public_id` IS NOT NULL
        AND `payment_eligibility_configuration_hash` IS NOT NULL
        AND `payment_eligibility_method_configuration_hash` IS NOT NULL
        AND `payment_method_version_id` IS NOT NULL
        AND `payment_method_code` IS NOT NULL
        AND `payment_method_version` IS NOT NULL
        AND `payment_method_configuration_hash` IS NOT NULL)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_purchase_hashes_chk CHECK (
    (`source_quote_configuration_hash` IS NULL OR CHAR_LENGTH(`source_quote_configuration_hash`) = 64)
    AND (`payment_eligibility_configuration_hash` IS NULL OR CHAR_LENGTH(`payment_eligibility_configuration_hash`) = 64)
    AND (`payment_eligibility_method_configuration_hash` IS NULL OR CHAR_LENGTH(`payment_eligibility_method_configuration_hash`) = 64)
    AND (`payment_method_configuration_hash` IS NULL OR CHAR_LENGTH(`payment_method_configuration_hash`) = 64)
)
SQL);

        $this->createExpandedInsertGuard();
        $this->createExpandedUpdateGuard();
    }

    public function down(): void
    {
        if (DB::table('payment_intents')->where('purpose', 'purchase')->exists()) {
            throw new RuntimeException('Cannot roll back purchase Payment Intent authority while purchase intents exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT payment_intent_purchase_hashes_chk');
        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT payment_intent_binding_shape_chk');
        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT payment_intent_purpose_chk');

        DB::statement(implode(' ', [
            'ALTER TABLE payment_intents',
            'DROP FOREIGN KEY payment_intent_source_quote_fk,',
            'DROP FOREIGN KEY payment_intent_eligibility_decision_fk,',
            'DROP FOREIGN KEY payment_intent_method_version_fk,',
            'DROP INDEX payment_intent_quote_state_idx,',
            'DROP INDEX payment_intent_decision_idx,',
            'DROP INDEX payment_intent_method_version_idx,',
            'DROP COLUMN payment_method_configuration_hash,',
            'DROP COLUMN payment_method_version,',
            'DROP COLUMN payment_method_code,',
            'DROP COLUMN payment_method_version_id,',
            'DROP COLUMN payment_eligibility_method_configuration_hash,',
            'DROP COLUMN payment_eligibility_configuration_hash,',
            'DROP COLUMN payment_eligibility_decision_public_id,',
            'DROP COLUMN payment_eligibility_decision_id,',
            'DROP COLUMN source_quote_configuration_hash,',
            'DROP COLUMN source_quote_public_id,',
            'DROP COLUMN source_quote_id',
        ]));
        DB::statement('ALTER TABLE payment_intents MODIFY wallet_account_id BIGINT UNSIGNED NOT NULL');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_purpose_chk CHECK (`purpose` = 'wallet_top_up')");

        $this->createLegacyInsertGuard();
        $this->createLegacyStrictUpdateGuard();
    }

    private function createExpandedInsertGuard(): void
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
        IF NEW.source_quote_id IS NOT NULL
           OR NEW.payment_eligibility_decision_id IS NOT NULL
           OR NEW.payment_method_version_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent cannot carry purchase authority bindings.';
        END IF;

        SELECT COUNT(*) INTO valid_wallet_count
        FROM ledger_accounts
        WHERE id = NEW.wallet_account_id
          AND owner_user_id = NEW.user_id
          AND account_class = 'liability'
          AND wallet_bucket = 'cash'
          AND currency = 'IRR'
          AND is_active = 1;

        IF valid_wallet_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.';
        END IF;
    ELSEIF NEW.purpose = 'purchase' THEN
        IF NEW.wallet_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent cannot carry a wallet top-up binding.';
        END IF;

        SELECT COUNT(*) INTO valid_quote_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id
          AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id
          AND quote_row.final_price_irr = NEW.amount_irr
          AND quote_row.currency = NEW.currency
          AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.valid_from <= NEW.created_at
          AND quote_row.expires_at > NEW.created_at;

        IF valid_quote_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one current matching immutable Quote.';
        END IF;

        SELECT COUNT(*) INTO valid_decision_count
        FROM payment_method_eligibility_decisions decision_row
        WHERE decision_row.id = NEW.payment_eligibility_decision_id
          AND decision_row.public_id = NEW.payment_eligibility_decision_public_id
          AND decision_row.source_quote_id = NEW.source_quote_id
          AND decision_row.source_quote_public_id = NEW.source_quote_public_id
          AND decision_row.user_id = NEW.user_id
          AND decision_row.action_snapshot = 'purchase'
          AND decision_row.currency_snapshot = NEW.currency
          AND decision_row.amount_irr_snapshot = NEW.amount_irr
          AND decision_row.configuration_snapshot_hash = NEW.payment_eligibility_configuration_hash
          AND decision_row.created_at <= NEW.created_at;

        IF valid_decision_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one matching PAY-001 eligibility decision.';
        END IF;

        SELECT COUNT(*) INTO valid_method_count
        FROM payment_method_eligibility_decision_methods decision_method
        INNER JOIN payment_method_versions method_version
            ON method_version.id = decision_method.payment_method_version_id
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

        IF valid_method_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one selected PAY-001 payment method version.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent purpose is unsupported.';
    END IF;
END
SQL);
    }

    private function createExpandedUpdateGuard(): void
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

    private function createLegacyInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_insert_guard
BEFORE INSERT ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_wallet_count
    FROM ledger_accounts
    WHERE id = NEW.wallet_account_id
      AND owner_user_id = NEW.user_id
      AND account_class = 'liability'
      AND wallet_bucket = 'cash'
      AND currency = 'IRR'
      AND is_active = 1;

    IF valid_wallet_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.';
    END IF;
END
SQL);
    }

    private function createLegacyStrictUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    IF NEW.public_id <> OLD.public_id
       OR NEW.creation_key <> OLD.creation_key
       OR NEW.payload_hash <> OLD.payload_hash
       OR NEW.purpose <> OLD.purpose
       OR NEW.user_id <> OLD.user_id
       OR NEW.wallet_account_id <> OLD.wallet_account_id
       OR NEW.provider_code <> OLD.provider_code
       OR NEW.amount_irr <> OLD.amount_irr
       OR NEW.currency <> OLD.currency
       OR NEW.creation_correlation_id <> OLD.creation_correlation_id
       OR NEW.created_at <> OLD.created_at THEN
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
