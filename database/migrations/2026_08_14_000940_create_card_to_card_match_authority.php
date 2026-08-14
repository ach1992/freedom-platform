<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement C2C-004 C2C-005 PAY-002 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        Schema::create('c2c_transaction_matches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('c2c_bank_transaction_id')->unique()->constrained('c2c_bank_transactions')->restrictOnDelete();
            $table->foreignId('c2c_amount_reservation_id')->unique()->constrained('c2c_amount_reservations')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->string('match_mode', 16);
            $table->string('state', 16)->default('matched');
            $table->foreignId('purchase_settlement_id')->nullable()->unique()->constrained('purchase_settlements')->restrictOnDelete();
            $table->dateTime('matched_at', 6);
            $table->dateTime('captured_at', 6)->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE c2c_transaction_matches ADD CONSTRAINT c2c_match_mode_chk CHECK (`match_mode` IN ('automatic','manual'))");
        DB::statement("ALTER TABLE c2c_transaction_matches ADD CONSTRAINT c2c_match_state_chk CHECK (`state` IN ('matched','captured'))");
        DB::statement("ALTER TABLE c2c_transaction_matches ADD CONSTRAINT c2c_match_capture_shape_chk CHECK ((`state` = 'matched' AND `purchase_settlement_id` IS NULL AND `captured_at` IS NULL) OR (`state` = 'captured' AND `purchase_settlement_id` IS NOT NULL AND `captured_at` IS NOT NULL))");

        Schema::create('c2c_match_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('c2c_bank_transaction_id')->unique()->constrained('c2c_bank_transactions')->restrictOnDelete();
            $table->string('reason_code', 32);
            $table->unsignedSmallInteger('candidate_count')->default(0);
            $table->string('state', 16)->default('pending');
            $table->foreignId('selected_c2c_amount_reservation_id')->nullable()->constrained('c2c_amount_reservations')->restrictOnDelete();
            $table->unsignedBigInteger('decided_by_administrator_id')->nullable();
            $table->string('decision_reason', 191)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->foreign('decided_by_administrator_id')->references('id')->on('administrators')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE c2c_match_reviews ADD CONSTRAINT c2c_review_reason_chk CHECK (`reason_code` IN ('no_candidate','ambiguous','late','transaction_not_settled','destination_mismatch','manual_required'))");
        DB::statement("ALTER TABLE c2c_match_reviews ADD CONSTRAINT c2c_review_state_chk CHECK (`state` IN ('pending','accepted','rejected'))");
        DB::statement("ALTER TABLE c2c_match_reviews ADD CONSTRAINT c2c_review_decision_shape_chk CHECK ((`state` = 'pending' AND `selected_c2c_amount_reservation_id` IS NULL AND `decided_by_administrator_id` IS NULL AND `decision_reason` IS NULL AND `decided_at` IS NULL) OR (`state` = 'accepted' AND `selected_c2c_amount_reservation_id` IS NOT NULL AND `decided_by_administrator_id` IS NOT NULL AND `decision_reason` IS NOT NULL AND `decided_at` IS NOT NULL) OR (`state` = 'rejected' AND `selected_c2c_amount_reservation_id` IS NULL AND `decided_by_administrator_id` IS NOT NULL AND `decision_reason` IS NOT NULL AND `decided_at` IS NOT NULL))");

        $this->createMatchGuards();
        $this->createReviewGuards();
    }

    public function down(): void
    {
        if (DB::table('c2c_transaction_matches')->exists() || DB::table('c2c_match_reviews')->exists()) {
            throw new RuntimeException('Cannot roll back C2C match authority after matching/review state exists.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_match_reviews_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_match_reviews_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_transaction_matches_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_transaction_matches_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_transaction_matches_insert_guard');
        Schema::dropIfExists('c2c_match_reviews');
        Schema::dropIfExists('c2c_transaction_matches');
    }

    private function createMatchGuards(): void
    {
        DB::unprepared(<<<'SQL'
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
      AND intent_row.state = 'created'
      AND intent_row.captured_at IS NULL;

    IF valid_match_count <> 1 OR NEW.state <> 'matched' OR NEW.purchase_settlement_id IS NOT NULL OR NEW.captured_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C transaction match requires one exact settled transaction/reservation authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_transaction_matches_update_guard
BEFORE UPDATE ON c2c_transaction_matches
FOR EACH ROW
BEGIN
    DECLARE valid_settlement_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.c2c_bank_transaction_id <=> OLD.c2c_bank_transaction_id)
       OR NOT (NEW.c2c_amount_reservation_id <=> OLD.c2c_amount_reservation_id)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.match_mode <=> OLD.match_mode)
       OR NOT (NEW.matched_at <=> OLD.matched_at)
       OR NOT (NEW.correlation_id <=> OLD.correlation_id)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C transaction match identity is immutable.';
    END IF;

    IF OLD.state = 'matched' AND NEW.state = 'captured' THEN
        SELECT COUNT(*) INTO valid_settlement_count
        FROM purchase_settlements settlement_row
        INNER JOIN c2c_bank_transactions transaction_row ON transaction_row.id = OLD.c2c_bank_transaction_id
        INNER JOIN c2c_amount_reservations reservation_row ON reservation_row.id = OLD.c2c_amount_reservation_id
        WHERE settlement_row.id = NEW.purchase_settlement_id
          AND settlement_row.payment_intent_id = OLD.payment_intent_id
          AND settlement_row.provider_code = 'card_to_card'
          AND settlement_row.provider_transaction_id = transaction_row.provider_transaction_id
          AND settlement_row.amount_irr = reservation_row.payable_amount_irr
          AND settlement_row.currency = 'IRR';
        IF valid_settlement_count <> 1 OR NEW.captured_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C captured match requires authoritative purchase settlement.';
        END IF;
    ELSEIF NOT (NEW.state <=> OLD.state)
       OR NOT (NEW.purchase_settlement_id <=> OLD.purchase_settlement_id)
       OR NOT (NEW.captured_at <=> OLD.captured_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C transaction match lifecycle transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_transaction_matches_delete_guard
BEFORE DELETE ON c2c_transaction_matches
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C transaction matches are non-deletable.';
END
SQL);
    }

    private function createReviewGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_match_reviews_update_guard
BEFORE UPDATE ON c2c_match_reviews
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.c2c_bank_transaction_id <=> OLD.c2c_bank_transaction_id)
       OR NOT (NEW.reason_code <=> OLD.reason_code)
       OR NOT (NEW.candidate_count <=> OLD.candidate_count)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C match review identity is immutable.';
    END IF;
    IF OLD.state <> 'pending' OR NEW.state NOT IN ('accepted','rejected') THEN
        IF NOT (NEW.state <=> OLD.state)
           OR NOT (NEW.selected_c2c_amount_reservation_id <=> OLD.selected_c2c_amount_reservation_id)
           OR NOT (NEW.decided_by_administrator_id <=> OLD.decided_by_administrator_id)
           OR NOT (NEW.decision_reason <=> OLD.decision_reason)
           OR NOT (NEW.decided_at <=> OLD.decided_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C match review decision is immutable after first decision.';
        END IF;
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_match_reviews_delete_guard
BEFORE DELETE ON c2c_match_reviews
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C match reviews are non-deletable.';
END
SQL);
    }
};
