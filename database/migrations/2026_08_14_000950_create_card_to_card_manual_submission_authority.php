<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement C2C-003 C2C-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('c2c_manual_submissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('submission_key', 128)->unique();
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('c2c_amount_reservation_id')->constrained('c2c_amount_reservations')->restrictOnDelete();
            $table->foreignId('c2c_destination_account_id')->constrained('c2c_destination_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->bigInteger('claimed_amount_irr');
            $table->dateTime('claimed_paid_at', 6);
            $table->char('sender_card_lookup_hash', 64)->nullable();
            $table->text('encrypted_sender_name')->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('private_receipt_reference', 191)->nullable();
            $table->char('evidence_hash', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['payment_intent_id', 'evidence_hash'], 'c2c_manual_intent_evidence_unique');
            $table->foreign('submitted_by_user_id')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE c2c_manual_submissions ADD CONSTRAINT c2c_manual_amount_chk CHECK (`claimed_amount_irr` > 0)');
        DB::statement('ALTER TABLE c2c_manual_submissions ADD CONSTRAINT c2c_manual_hash_chk CHECK (CHAR_LENGTH(`evidence_hash`) = 64 AND (`sender_card_lookup_hash` IS NULL OR CHAR_LENGTH(`sender_card_lookup_hash`) = 64))');

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
      AND intent_row.state = 'created'
      AND intent_row.captured_at IS NULL;

    IF valid_submission_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C manual submission must match one owned purchase reservation and exact payable amount.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_manual_submissions_update_guard
BEFORE UPDATE ON c2c_manual_submissions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C manual submissions are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_manual_submissions_delete_guard
BEFORE DELETE ON c2c_manual_submissions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C manual submissions are non-deletable.';
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('c2c_manual_submissions')->exists()) {
            throw new RuntimeException('Cannot roll back C2C manual submission authority after evidence exists.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_manual_submissions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_manual_submissions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_manual_submissions_insert_guard');
        Schema::dropIfExists('c2c_manual_submissions');
    }
};
