<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-001 PAY-002 WAL-002 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_wallet_terminal_state_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE wallet_reservation_count INT DEFAULT 0;
    DECLARE released_authority_count INT DEFAULT 0;

    IF OLD.state = 'awaiting_user_action'
       AND NEW.state IN ('expired','canceled')
       AND OLD.purpose = 'purchase'
       AND OLD.provider_code = 'wallet'
       AND OLD.payment_method_code = 'wallet' THEN
        SELECT COUNT(*) INTO wallet_reservation_count
        FROM purchase_wallet_reservations
        WHERE payment_intent_id = OLD.id;

        IF wallet_reservation_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase terminal transition requires one reservation authority.';
        END IF;

        SELECT COUNT(*) INTO released_authority_count
        FROM purchase_wallet_reservations reservation_row
        INNER JOIN wallet_holds hold_row ON hold_row.id = reservation_row.wallet_hold_id
        WHERE reservation_row.payment_intent_id = OLD.id
          AND reservation_row.wallet_account_id = hold_row.ledger_account_id
          AND hold_row.owner_user_id = OLD.user_id
          AND hold_row.source_type = 'payment_intent'
          AND hold_row.source_id = OLD.public_id
          AND hold_row.status = 'released'
          AND hold_row.captured_ledger_transaction_id IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = OLD.id
          );

        IF released_authority_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase expiry/cancellation requires its exact released hold and no settlement.';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_wallet_reservations') && DB::table('purchase_wallet_reservations')->exists()) {
            throw new RuntimeException('Cannot remove wallet terminal state guard after reservation facts exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_terminal_state_guard');
    }
};
