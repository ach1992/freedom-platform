<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement USDT-003 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        if (DB::table('usdt_payment_authorities')->exists()) {
            throw new RuntimeException('Cannot reinterpret pre-release USDT authorities from 6-decimal raw units; clear non-production USDT payment data first.');
        }

        DB::statement("ALTER TABLE usdt_payment_authorities ADD COLUMN token_decimals TINYINT UNSIGNED NOT NULL DEFAULT 18 AFTER token_contract");
        DB::statement('ALTER TABLE usdt_payment_authorities MODIFY expected_amount_base_units DECIMAL(65,0) UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE usdt_chain_verification_events MODIFY amount_base_units DECIMAL(65,0) UNSIGNED NULL');
        DB::statement('ALTER TABLE usdt_verified_transfers MODIFY amount_base_units DECIMAL(65,0) UNSIGNED NOT NULL');
        DB::statement("ALTER TABLE usdt_payment_authorities ADD CONSTRAINT usdt_payment_authority_asset_chk CHECK (`chain_id` = 56 AND `token_contract` = '0x55d398326f99059ff775485246999027b3197955' AND `token_decimals` = 18)");

        DB::unprepared('DROP TRIGGER IF EXISTS usdt_payment_authorities_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_payment_authorities_insert_guard
BEFORE INSERT ON usdt_payment_authorities
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count
    FROM payment_intents intent_row
    INNER JOIN usdt_amount_quotes amount_quote ON amount_quote.id = NEW.usdt_amount_quote_id
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'usdt_bep20'
      AND intent_row.provider_code = 'usdt_bep20'
      AND intent_row.user_id = NEW.user_id
      AND intent_row.source_quote_id = NEW.source_quote_id
      AND intent_row.source_quote_public_id = NEW.source_quote_public_id
      AND intent_row.amount_irr = NEW.source_amount_irr
      AND intent_row.currency = 'IRR'
      AND intent_row.state = 'awaiting_user_action'
      AND intent_row.captured_at IS NULL
      AND amount_quote.user_id = NEW.user_id
      AND amount_quote.source_quote_id = NEW.source_quote_id
      AND amount_quote.source_quote_public_id = NEW.source_quote_public_id
      AND amount_quote.order_amount_irr = NEW.source_amount_irr
      AND amount_quote.destination_wallet_version_id = NEW.destination_wallet_version_id
      AND amount_quote.destination_address = NEW.destination_address
      AND amount_quote.network = NEW.network
      AND amount_quote.destination_configuration_hash = NEW.destination_configuration_hash
      AND amount_quote.configuration_snapshot_hash = NEW.amount_quote_configuration_hash
      AND amount_quote.expires_at = NEW.quote_expires_at
      AND amount_quote.expires_at > NEW.created_at
      AND NEW.network = 'BEP20'
      AND NEW.chain_id = 56
      AND NEW.token_contract = '0x55d398326f99059ff775485246999027b3197955'
      AND NEW.token_decimals = 18
      AND (amount_quote.exact_usdt * 1000000000000000000) = NEW.expected_amount_base_units;
    IF valid_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT payment authority requires one current exact canonical BSC-USDT binding.';
    END IF;
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS usdt_verified_transfers_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_verified_transfers_insert_guard
BEFORE INSERT ON usdt_verified_transfers
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    DECLARE approved_review_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count
    FROM usdt_payment_authorities authority_row
    INNER JOIN usdt_txid_submissions submission_row ON submission_row.id = NEW.usdt_txid_submission_id
    INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
    INNER JOIN usdt_chain_verification_events event_row ON event_row.id = NEW.provider_event_row_id
    WHERE authority_row.id = NEW.usdt_payment_authority_id
      AND authority_row.payment_intent_id = NEW.payment_intent_id
      AND submission_row.usdt_payment_authority_id = authority_row.id
      AND submission_row.payment_intent_id = NEW.payment_intent_id
      AND submission_row.txid = NEW.txid
      AND (
        (submission_row.state = 'verifying' AND intent_row.state = 'verifying') OR
        (submission_row.state = 'pending_manual_review' AND intent_row.state = 'pending_manual_review')
      )
      AND intent_row.captured_at IS NULL
      AND event_row.usdt_txid_submission_id = submission_row.id
      AND event_row.provider_code = NEW.provider_code
      AND event_row.txid = NEW.txid
      AND event_row.outcome = 'success'
      AND event_row.transaction_status = 'success'
      AND event_row.network = authority_row.network
      AND event_row.chain_id = authority_row.chain_id
      AND event_row.token_contract = authority_row.token_contract
      AND event_row.destination_address = authority_row.destination_address
      AND event_row.amount_base_units = authority_row.expected_amount_base_units
      AND event_row.token_decimals = authority_row.token_decimals
      AND event_row.confirmations >= authority_row.minimum_confirmations
      AND event_row.evidence_hash = NEW.evidence_hash
      AND NEW.amount_base_units = authority_row.expected_amount_base_units
      AND NEW.confirmations = event_row.confirmations
      AND NEW.transaction_at = event_row.transaction_at;
    IF valid_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT verified transfer requires one exact authoritative canonical BSC-USDT observation.';
    END IF;

    IF (SELECT state FROM usdt_txid_submissions WHERE id = NEW.usdt_txid_submission_id) = 'verifying' THEN
        IF NEW.transaction_at < (SELECT created_at FROM usdt_payment_authorities WHERE id = NEW.usdt_payment_authority_id)
           OR NEW.transaction_at > (SELECT quote_expires_at FROM usdt_payment_authorities WHERE id = NEW.usdt_payment_authority_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT automatic verification cannot accept a transaction outside the locked quote window.';
        END IF;
    ELSE
        SELECT COUNT(*) INTO approved_review_count FROM usdt_manual_reviews review_row
        WHERE review_row.usdt_txid_submission_id = NEW.usdt_txid_submission_id
          AND review_row.state = 'approved'
          AND review_row.decided_by_administrator_id IS NOT NULL
          AND review_row.decision_reason IS NOT NULL
          AND review_row.decided_at IS NOT NULL;
        IF approved_review_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual verified transfer requires an approved review.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_settlements_insert_guard
BEFORE INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    DECLARE valid_intent_count INT DEFAULT 0;
    DECLARE valid_provider_count INT DEFAULT 0;
    DECLARE valid_c2c_count INT DEFAULT 0;
    DECLARE valid_gift_card_count INT DEFAULT 0;
    DECLARE valid_usdt_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.wallet_account_id IS NULL
      AND intent_row.user_id = NEW.user_id
      AND intent_row.source_quote_id = NEW.source_quote_id
      AND intent_row.source_quote_public_id = NEW.source_quote_public_id
      AND intent_row.provider_code = NEW.provider_code
      AND intent_row.currency = NEW.currency
      AND intent_row.state IN ('submitted','verifying','pending_manual_review','authorized')
      AND intent_row.captured_at IS NULL
      AND (NEW.provider_code = 'card_to_card' OR intent_row.amount_irr = NEW.amount_irr);

    IF valid_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase settlement requires one matching pre-capture purchase intent.';
    END IF;

    IF NEW.provider_code = 'card_to_card' THEN
        SELECT COUNT(*) INTO valid_c2c_count
        FROM c2c_transaction_matches match_row
        INNER JOIN c2c_bank_transactions transaction_row ON transaction_row.id = match_row.c2c_bank_transaction_id
        INNER JOIN c2c_amount_reservations reservation_row ON reservation_row.id = match_row.c2c_amount_reservation_id
        WHERE match_row.payment_intent_id = NEW.payment_intent_id
          AND match_row.state = 'matched'
          AND match_row.purchase_settlement_id IS NULL
          AND transaction_row.status = 'settled'
          AND SHA2(CONCAT(transaction_row.provider_code, CHAR(0), transaction_row.provider_transaction_id), 256) = NEW.provider_transaction_id
          AND transaction_row.amount_irr = NEW.amount_irr
          AND transaction_row.currency = NEW.currency
          AND reservation_row.payment_intent_id = NEW.payment_intent_id
          AND reservation_row.payable_amount_irr = NEW.amount_irr;
        IF valid_c2c_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card settlement requires one accepted exact payable match.';
        END IF;
    END IF;

    IF NEW.provider_code = 'gift_card' THEN
        SELECT COUNT(*) INTO valid_gift_card_count
        FROM gift_card_redemptions redemption_row
        INNER JOIN gift_card_submissions submission_row ON submission_row.id = redemption_row.gift_card_submission_id
        INNER JOIN gift_card_provider_events event_row ON event_row.id = redemption_row.provider_event_row_id
        WHERE redemption_row.payment_intent_id = NEW.payment_intent_id
          AND redemption_row.purchase_settlement_id IS NULL
          AND redemption_row.amount_irr = NEW.amount_irr
          AND redemption_row.currency = NEW.currency
          AND submission_row.id = redemption_row.gift_card_submission_id
          AND submission_row.payment_intent_id = NEW.payment_intent_id
          AND submission_row.state = 'redeeming'
          AND submission_row.claimed_face_value = NEW.amount_irr
          AND submission_row.claimed_currency = NEW.currency
          AND event_row.gift_card_submission_id = submission_row.id
          AND event_row.provider_code = redemption_row.provider_code
          AND event_row.operation = 'redeem'
          AND event_row.outcome = 'success'
          AND event_row.provider_status = 'redeemed'
          AND event_row.provider_transaction_id = redemption_row.provider_redemption_id
          AND event_row.face_value = NEW.amount_irr
          AND event_row.currency = NEW.currency
          AND event_row.evidence_hash = NEW.evidence_payload_hash
          AND SHA2(CONCAT(redemption_row.provider_code, CHAR(0), redemption_row.provider_redemption_id), 256) = NEW.provider_transaction_id;
        IF valid_gift_card_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card settlement requires one exact authoritative redemption.';
        END IF;
    END IF;

    IF NEW.provider_code = 'usdt_bep20' THEN
        SELECT COUNT(*) INTO valid_usdt_count
        FROM usdt_verified_transfers transfer_row
        INNER JOIN usdt_txid_submissions submission_row ON submission_row.id = transfer_row.usdt_txid_submission_id
        INNER JOIN usdt_payment_authorities authority_row ON authority_row.id = transfer_row.usdt_payment_authority_id
        INNER JOIN usdt_chain_verification_events event_row ON event_row.id = transfer_row.provider_event_row_id
        WHERE transfer_row.payment_intent_id = NEW.payment_intent_id
          AND transfer_row.purchase_settlement_id IS NULL
          AND submission_row.id = transfer_row.usdt_txid_submission_id
          AND submission_row.payment_intent_id = NEW.payment_intent_id
          AND submission_row.state = 'verified'
          AND submission_row.txid = transfer_row.txid
          AND authority_row.id = transfer_row.usdt_payment_authority_id
          AND authority_row.payment_intent_id = NEW.payment_intent_id
          AND authority_row.source_amount_irr = NEW.amount_irr
          AND authority_row.chain_id = 56
          AND authority_row.token_contract = '0x55d398326f99059ff775485246999027b3197955'
          AND authority_row.token_decimals = 18
          AND event_row.usdt_txid_submission_id = submission_row.id
          AND event_row.provider_code = transfer_row.provider_code
          AND event_row.txid = transfer_row.txid
          AND event_row.outcome = 'success'
          AND event_row.transaction_status = 'success'
          AND event_row.network = authority_row.network
          AND event_row.chain_id = authority_row.chain_id
          AND event_row.token_contract = authority_row.token_contract
          AND event_row.destination_address = authority_row.destination_address
          AND event_row.amount_base_units = authority_row.expected_amount_base_units
          AND event_row.token_decimals = authority_row.token_decimals
          AND event_row.confirmations >= authority_row.minimum_confirmations
          AND event_row.evidence_hash = transfer_row.evidence_hash
          AND transfer_row.evidence_hash = NEW.evidence_payload_hash
          AND SHA2(CONCAT('BEP20', CHAR(0), transfer_row.txid), 256) = NEW.provider_transaction_id;
        IF valid_usdt_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT settlement requires one exact verified canonical BSC-USDT transfer.';
        END IF;
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
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only correction: canonical BSC-USDT raw-unit precision cannot be safely downgraded.');
    }
};
