<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement C2C-002 C2C-004 C2C-005 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_amount_reservations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_transaction_matches_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_insert_guard');

        $this->createReservationInsertGuard('awaiting_user_action');
        $this->createMatchInsertGuard('awaiting_user_action');
        $this->createPayableAwarePurchaseSettlementGuard();
    }

    public function down(): void
    {
        if (DB::table('c2c_transaction_matches')->where('state', 'captured')->exists()
            || DB::table('purchase_settlements')->where('provider_code', 'card_to_card')->exists()) {
            throw new RuntimeException('Cannot roll back C2C payable settlement authority after card-to-card capture exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS c2c_amount_reservations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_transaction_matches_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_insert_guard');

        $this->createReservationInsertGuard('created');
        $this->createMatchInsertGuard('created');
        $this->createPriorPurchaseSettlementGuard();
    }

    private function createReservationInsertGuard(string $intentState): void
    {
        $state = str_replace("'", "''", $intentState);
        DB::unprepared(<<<SQL
CREATE TRIGGER c2c_amount_reservations_insert_guard
BEFORE INSERT ON c2c_amount_reservations
FOR EACH ROW
BEGIN
    DECLARE valid_intent_count INT DEFAULT 0;
    DECLARE valid_destination_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'card_to_card'
      AND intent_row.provider_code = 'card_to_card'
      AND intent_row.amount_irr = NEW.base_amount_irr
      AND intent_row.currency = 'IRR'
      AND intent_row.state = '{$state}'
      AND intent_row.captured_at IS NULL;
    IF valid_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card reservation requires one matching purchase payment intent.';
    END IF;

    SELECT COUNT(*) INTO valid_destination_count
    FROM c2c_destination_accounts destination_row
    WHERE destination_row.id = NEW.c2c_destination_account_id
      AND destination_row.state = 'active'
      AND ((destination_row.adjustment_enabled = 0 AND NEW.adjustment_amount_irr = 0)
        OR (destination_row.adjustment_enabled = 1
          AND NEW.adjustment_amount_irr BETWEEN destination_row.adjustment_min_irr AND destination_row.adjustment_max_irr));
    IF valid_destination_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card reservation destination/adjustment policy is invalid.';
    END IF;
END
SQL);
    }

    private function createMatchInsertGuard(string $intentState): void
    {
        $state = str_replace("'", "''", $intentState);
        DB::unprepared(<<<SQL
CREATE TRIGGER c2c_transaction_matches_insert_guard
BEFORE INSERT ON c2c_transaction_matches
FOR EACH ROW
BEGIN
    DECLARE valid_match_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_match_count
    FROM c2c_bank_transactions transaction_row
    INNER JOIN c2c_amount_reservations reservation_row
      ON reservation_row.id = NEW.c2c_amount_reservation_id
    INNER JOIN payment_intents intent_row
      ON intent_row.id = reservation_row.payment_intent_id
    WHERE transaction_row.id = NEW.c2c_bank_transaction_id
      AND transaction_row.status = 'settled'
      AND transaction_row.c2c_destination_account_id = reservation_row.c2c_destination_account_id
      AND transaction_row.amount_irr = reservation_row.payable_amount_irr
      AND transaction_row.currency = 'IRR'
      AND transaction_row.occurred_at >= reservation_row.reserved_at
      AND ((NEW.match_mode = 'automatic' AND transaction_row.occurred_at <= reservation_row.expires_at)
        OR (NEW.match_mode = 'manual' AND transaction_row.occurred_at <= reservation_row.late_review_until))
      AND intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'card_to_card'
      AND intent_row.provider_code = 'card_to_card'
      AND intent_row.state = '{$state}'
      AND intent_row.captured_at IS NULL;

    IF valid_match_count <> 1 OR NEW.state <> 'matched' OR NEW.purchase_settlement_id IS NOT NULL OR NEW.captured_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C transaction match requires one exact settled transaction/reservation authority.';
    END IF;
END
SQL);
    }

    private function createPayableAwarePurchaseSettlementGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_settlements_insert_guard
BEFORE INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    DECLARE valid_intent_count INT DEFAULT 0;
    DECLARE valid_provider_count INT DEFAULT 0;
    DECLARE valid_c2c_count INT DEFAULT 0;

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
        INNER JOIN c2c_bank_transactions transaction_row
          ON transaction_row.id = match_row.c2c_bank_transaction_id
        INNER JOIN c2c_amount_reservations reservation_row
          ON reservation_row.id = match_row.c2c_amount_reservation_id
        WHERE match_row.payment_intent_id = NEW.payment_intent_id
          AND match_row.state = 'matched'
          AND match_row.purchase_settlement_id IS NULL
          AND transaction_row.status = 'settled'
          AND transaction_row.provider_transaction_id = NEW.provider_transaction_id
          AND transaction_row.amount_irr = NEW.amount_irr
          AND transaction_row.currency = NEW.currency
          AND reservation_row.payment_intent_id = NEW.payment_intent_id
          AND reservation_row.payable_amount_irr = NEW.amount_irr;

        IF valid_c2c_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card settlement requires one accepted exact payable match.';
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

    private function createPriorPurchaseSettlementGuard(): void
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
    }
};
