<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_manual_submissions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_transaction_matches_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_manual_submissions_insert_guard
BEFORE INSERT ON c2c_manual_submissions
FOR EACH ROW
BEGIN
    DECLARE valid_submission_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_submission_count
    FROM c2c_amount_reservations reservation_row
    INNER JOIN payment_intents intent_row ON intent_row.id = reservation_row.payment_intent_id
    WHERE reservation_row.id = NEW.c2c_amount_reservation_id
      AND reservation_row.payment_intent_id = NEW.payment_intent_id
      AND reservation_row.c2c_destination_account_id = NEW.c2c_destination_account_id
      AND reservation_row.payable_amount_irr = NEW.claimed_amount_irr
      AND reservation_row.reserved_at <= NEW.claimed_paid_at
      AND reservation_row.late_review_until >= NEW.claimed_paid_at
      AND intent_row.user_id = NEW.submitted_by_user_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'card_to_card'
      AND intent_row.provider_code = 'card_to_card'
      AND intent_row.state = 'awaiting_user_action'
      AND intent_row.captured_at IS NULL;
    IF valid_submission_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C manual submission must match one owned purchase reservation and exact payable amount.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_transaction_matches_insert_guard
BEFORE INSERT ON c2c_transaction_matches
FOR EACH ROW
BEGIN
    DECLARE valid_match_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_match_count
    FROM c2c_bank_transactions transaction_row
    INNER JOIN c2c_amount_reservations reservation_row ON reservation_row.id = NEW.c2c_amount_reservation_id
    INNER JOIN payment_intents intent_row ON intent_row.id = reservation_row.payment_intent_id
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
      AND intent_row.state IN ('awaiting_user_action','submitted')
      AND intent_row.captured_at IS NULL;
    IF valid_match_count <> 1 OR NEW.state <> 'matched' OR NEW.purchase_settlement_id IS NOT NULL OR NEW.captured_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C transaction match requires one exact settled transaction/reservation authority.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('c2c_manual_submissions')->exists() || DB::table('c2c_transaction_matches')->exists()) {
            throw new RuntimeException('Cannot roll back corrected C2C lifecycle guards while evidence exists.');
        }
        throw new RuntimeException('Corrected C2C lifecycle guard migration is intentionally forward-only.');
    }
};
