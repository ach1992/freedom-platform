<?php

declare(strict_types=1);

use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-002 PAY-001 PAY-002 PAY-003 WAL-001 WAL-002 WAL-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        Schema::create('purchase_wallet_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('payment_intent_id')->unique();
            $table->unsignedBigInteger('wallet_account_id');
            $table->unsignedBigInteger('wallet_hold_id')->unique();
            $table->timestamp('created_at', 6);

            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->restrictOnDelete();
            $table->foreign('wallet_account_id')->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('wallet_hold_id')->references('id')->on('wallet_holds')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_wallet_reservations_insert_guard
BEFORE INSERT ON purchase_wallet_reservations
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_authority_count
    FROM payment_intents intent_row
    INNER JOIN quotes quote_row
      ON quote_row.id = intent_row.source_quote_id
    INNER JOIN ledger_accounts wallet_row
      ON wallet_row.id = NEW.wallet_account_id
    INNER JOIN wallet_holds hold_row
      ON hold_row.id = NEW.wallet_hold_id
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'wallet'
      AND intent_row.provider_code = 'wallet'
      AND intent_row.wallet_account_id IS NULL
      AND intent_row.currency = 'IRR'
      AND intent_row.state = 'awaiting_user_action'
      AND wallet_row.owner_user_id = intent_row.user_id
      AND wallet_row.account_class = 'liability'
      AND wallet_row.wallet_bucket = 'cash'
      AND wallet_row.currency = 'IRR'
      AND wallet_row.is_active = 1
      AND hold_row.owner_user_id = intent_row.user_id
      AND hold_row.ledger_account_id = wallet_row.id
      AND hold_row.amount_irr = intent_row.amount_irr
      AND hold_row.source_type = 'payment_intent'
      AND hold_row.source_id = intent_row.public_id
      AND hold_row.status = 'active'
      AND hold_row.expires_at <= quote_row.expires_at;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase reservation requires one exact purchase intent, owned cash wallet, and active hold.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_wallet_reservations_update_guard
BEFORE UPDATE ON purchase_wallet_reservations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase reservation authority is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_wallet_reservations_delete_guard
BEFORE DELETE ON purchase_wallet_reservations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase reservation authority cannot be deleted.';
END
SQL);

        $purchaseOffsetCode = WalletSystemAccountCode::PURCHASE_CLEARING;
        DB::unprepared(<<<SQL
CREATE TRIGGER purchase_wallet_settlements_insert_guard
BEFORE INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_count INT DEFAULT 0;

    IF NEW.provider_code = 'wallet' THEN
        SELECT COUNT(*) INTO valid_wallet_count
        FROM purchase_wallet_reservations reservation_row
        INNER JOIN payment_intents intent_row
          ON intent_row.id = reservation_row.payment_intent_id
        INNER JOIN wallet_holds hold_row
          ON hold_row.id = reservation_row.wallet_hold_id
        INNER JOIN ledger_transactions ledger_row
          ON ledger_row.id = hold_row.captured_ledger_transaction_id
        INNER JOIN ledger_accounts offset_row
          ON offset_row.code = '{$purchaseOffsetCode}'
        WHERE reservation_row.payment_intent_id = NEW.payment_intent_id
          AND intent_row.public_id = hold_row.source_id
          AND intent_row.provider_code = 'wallet'
          AND intent_row.payment_method_code = 'wallet'
          AND intent_row.wallet_account_id IS NULL
          AND reservation_row.wallet_account_id = hold_row.ledger_account_id
          AND hold_row.source_type = 'payment_intent'
          AND hold_row.status = 'captured'
          AND hold_row.amount_irr = NEW.amount_irr
          AND hold_row.captured_at IS NOT NULL
          AND ledger_row.finalized_at IS NOT NULL
          AND ledger_row.transaction_type = 'wallet_hold_capture'
          AND ledger_row.source_type = 'wallet_hold'
          AND ledger_row.source_id = CAST(hold_row.id AS CHAR)
          AND offset_row.owner_user_id IS NULL
          AND offset_row.wallet_bucket IS NULL
          AND offset_row.account_class = 'revenue'
          AND offset_row.currency = 'IRR'
          AND SHA2(CONCAT('wallet', CHAR(0), 'ledger:', ledger_row.id), 256) = NEW.provider_transaction_id
          AND (
              SELECT COUNT(*)
              FROM ledger_entries entry_row
              WHERE entry_row.ledger_transaction_id = ledger_row.id
          ) = 2
          AND (
              SELECT COUNT(*)
              FROM ledger_entries debit_row
              WHERE debit_row.ledger_transaction_id = ledger_row.id
                AND debit_row.ledger_account_id = reservation_row.wallet_account_id
                AND debit_row.direction = 'debit'
                AND debit_row.amount_irr = NEW.amount_irr
          ) = 1
          AND (
              SELECT COUNT(*)
              FROM ledger_entries credit_row
              WHERE credit_row.ledger_transaction_id = ledger_row.id
                AND credit_row.ledger_account_id = offset_row.id
                AND credit_row.direction = 'credit'
                AND credit_row.amount_irr = NEW.amount_irr
          ) = 1;

        IF valid_wallet_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet settlement requires one exact captured hold and finalized purchase ledger effect.';
        END IF;
    END IF;
END
SQL);

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

    public function down(): void
    {
        if (Schema::hasTable('purchase_wallet_reservations') && DB::table('purchase_wallet_reservations')->exists()) {
            throw new RuntimeException('Cannot remove wallet purchase reservation authority after reservation facts exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_refunds_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_settlements_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_reservations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_reservations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_wallet_reservations_insert_guard');
        Schema::dropIfExists('purchase_wallet_reservations');
    }
};
