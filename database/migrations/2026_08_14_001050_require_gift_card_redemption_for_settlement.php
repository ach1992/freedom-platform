<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_insert_guard');
        $this->createGuard(true);
    }

    public function down(): void
    {
        if (DB::table('purchase_settlements')->where('provider_code', 'gift_card')->exists()) {
            throw new RuntimeException('Cannot remove gift-card settlement authority after gift-card capture exists.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_insert_guard');
        $this->createGuard(false);
    }

    private function createGuard(bool $withGiftCard): void
    {
        $giftCardBlock = $withGiftCard ? <<<'SQL'

    IF NEW.provider_code = 'gift_card' THEN
        SELECT COUNT(*) INTO valid_gift_card_count
        FROM gift_card_redemptions redemption_row
        INNER JOIN gift_card_submissions submission_row
          ON submission_row.id = redemption_row.gift_card_submission_id
        INNER JOIN gift_card_provider_events event_row
          ON event_row.id = redemption_row.provider_event_row_id
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
SQL : '';

        DB::unprepared(<<<SQL
CREATE TRIGGER purchase_settlements_insert_guard
BEFORE INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    DECLARE valid_intent_count INT DEFAULT 0;
    DECLARE valid_provider_count INT DEFAULT 0;
    DECLARE valid_c2c_count INT DEFAULT 0;
    DECLARE valid_gift_card_count INT DEFAULT 0;

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
          AND SHA2(CONCAT(transaction_row.provider_code, CHAR(0), transaction_row.provider_transaction_id), 256) = NEW.provider_transaction_id
          AND transaction_row.amount_irr = NEW.amount_irr
          AND transaction_row.currency = NEW.currency
          AND reservation_row.payment_intent_id = NEW.payment_intent_id
          AND reservation_row.payable_amount_irr = NEW.amount_irr;

        IF valid_c2c_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card settlement requires one accepted exact payable match.';
        END IF;
    END IF;
{$giftCardBlock}

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
