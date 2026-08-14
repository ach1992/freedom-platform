<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement USDT-003 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_chain_events_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_chain_events_insert_guard
BEFORE INSERT ON usdt_chain_verification_events
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count
    FROM usdt_txid_submissions submission_row
    INNER JOIN payment_intents intent_row ON intent_row.id = submission_row.payment_intent_id
    WHERE submission_row.id = NEW.usdt_txid_submission_id
      AND submission_row.txid = NEW.txid
      AND (
        (submission_row.state = 'verifying' AND intent_row.state = 'verifying') OR
        (submission_row.state = 'pending_manual_review' AND intent_row.state = 'pending_manual_review')
      )
      AND intent_row.captured_at IS NULL;
    IF valid_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT chain event requires a matching active verification lifecycle.';
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
      AND event_row.token_decimals = 6
      AND event_row.confirmations >= authority_row.minimum_confirmations
      AND event_row.evidence_hash = NEW.evidence_hash
      AND NEW.amount_base_units = authority_row.expected_amount_base_units
      AND NEW.confirmations = event_row.confirmations
      AND NEW.transaction_at = event_row.transaction_at;
    IF valid_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT verified transfer requires one exact authoritative active-chain observation.';
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

        DB::unprepared('DROP TRIGGER IF EXISTS usdt_txid_submissions_update_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_txid_submissions_update_guard
BEFORE UPDATE ON usdt_txid_submissions
FOR EACH ROW
BEGIN
    DECLARE lifecycle_count INT DEFAULT 0;
    IF NOT (NEW.public_id <=> OLD.public_id) OR NOT (NEW.submission_key <=> OLD.submission_key)
       OR NOT (NEW.usdt_payment_authority_id <=> OLD.usdt_payment_authority_id) OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.txid <=> OLD.txid)
       OR NOT (NEW.private_evidence_reference <=> OLD.private_evidence_reference) OR NOT (NEW.evidence_content_hash <=> OLD.evidence_content_hash)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash) OR NOT (NEW.submitted_at <=> OLD.submitted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT TXID submission identity is immutable.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('verified','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'provider_unavailable' AND NEW.state IN ('verifying','pending_manual_review','rejected')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','verified','rejected')) OR
        (OLD.state = 'verified' AND NEW.state = 'captured')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT TXID submission state transition is invalid.';
    END IF;

    IF OLD.state <> NEW.state AND NEW.state = 'verified' THEN
        SELECT COUNT(*) INTO lifecycle_count FROM usdt_verified_transfers transfer_row
        WHERE transfer_row.usdt_txid_submission_id = OLD.id
          AND transfer_row.payment_intent_id = OLD.payment_intent_id
          AND transfer_row.purchase_settlement_id IS NULL;
        IF lifecycle_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT submission cannot become verified without one persisted verified transfer.';
        END IF;
    END IF;

    IF OLD.state <> NEW.state AND NEW.state = 'captured' THEN
        SELECT COUNT(*) INTO lifecycle_count
        FROM usdt_verified_transfers transfer_row
        INNER JOIN purchase_settlements settlement_row ON settlement_row.id = transfer_row.purchase_settlement_id
        INNER JOIN payment_intents intent_row ON intent_row.id = OLD.payment_intent_id
        WHERE transfer_row.usdt_txid_submission_id = OLD.id
          AND transfer_row.payment_intent_id = OLD.payment_intent_id
          AND settlement_row.payment_intent_id = OLD.payment_intent_id
          AND settlement_row.provider_code = 'usdt_bep20'
          AND intent_row.state = 'captured'
          AND intent_row.captured_at IS NOT NULL;
        IF lifecycle_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT submission cannot become captured without linked purchase settlement.';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('usdt_verified_transfers')->exists()) {
            throw new RuntimeException('Cannot remove hardened USDT lifecycle after verified transfer exists.');
        }
        throw new RuntimeException('Forward-only USDT lifecycle hardening is not safely reversible after deployment.');
    }
};
