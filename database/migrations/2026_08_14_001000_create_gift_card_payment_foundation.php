<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement GFT-001 GFT-002 GFT-003 GFT-004 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('gift_card_types', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('type_code', 64)->unique();
            $table->string('display_name', 128);
            $table->string('brand', 64);
            $table->string('region', 64)->nullable();
            $table->string('face_currency', 3);
            $table->string('submission_mode', 24);
            $table->string('verification_mode', 48);
            $table->string('provider_code', 64);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('version')->default(1);
            $table->char('configuration_hash', 64);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
        DB::statement("ALTER TABLE gift_card_types ADD CONSTRAINT gift_card_type_submission_mode_chk CHECK (`submission_mode` IN ('image_only','code_only','either','both'))");
        DB::statement("ALTER TABLE gift_card_types ADD CONSTRAINT gift_card_type_verification_mode_chk CHECK (`verification_mode` IN ('manual_only','automatic_only','automatic_then_manual','automatic_with_manual_approval_above_limit','manual_fallback_on_provider_failure'))");
        DB::statement('ALTER TABLE gift_card_types ADD CONSTRAINT gift_card_type_hash_chk CHECK (CHAR_LENGTH(`configuration_hash`) = 64)');

        Schema::create('gift_card_submissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('submission_key', 128)->unique();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('gift_card_type_id')->constrained('gift_card_types')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('encrypted_code')->nullable();
            $table->char('code_lookup_hash', 64)->nullable()->unique();
            $table->string('masked_code', 64)->nullable();
            $table->string('private_image_reference', 191)->nullable();
            $table->string('telegram_file_id', 191)->nullable();
            $table->string('telegram_file_unique_id', 191)->nullable();
            $table->char('image_content_hash', 64)->nullable();
            $table->bigInteger('claimed_face_value');
            $table->char('claimed_currency', 3);
            $table->string('claimed_brand', 64);
            $table->string('claimed_region', 64)->nullable();
            $table->string('state', 32)->default('submitted');
            $table->char('request_payload_hash', 64);
            $table->dateTime('submitted_at', 6);
            $table->dateTime('created_at', 6);
            $table->index(['gift_card_type_id', 'state'], 'gift_card_submission_type_state_idx');
        });
        DB::statement('ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_value_chk CHECK (`claimed_face_value` > 0)');
        DB::statement("ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_state_chk CHECK (`state` IN ('submitted','validating','valid_unreserved','reserved','redeeming','captured','pending_manual_review','invalid','already_used','expired','provider_unavailable','rejected','released'))");
        DB::statement('ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_hash_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND (`code_lookup_hash` IS NULL OR CHAR_LENGTH(`code_lookup_hash`) = 64) AND (`image_content_hash` IS NULL OR CHAR_LENGTH(`image_content_hash`) = 64))');

        Schema::create('gift_card_provider_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('gift_card_submission_id')->constrained('gift_card_submissions')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_event_id', 191);
            $table->string('operation', 24);
            $table->string('outcome', 32);
            $table->string('provider_status', 64);
            $table->string('provider_transaction_id', 191)->nullable();
            $table->bigInteger('face_value')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('brand', 64)->nullable();
            $table->string('region', 64)->nullable();
            $table->char('evidence_hash', 64);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('created_at', 6);
            $table->unique(['provider_code', 'provider_event_id'], 'gift_card_provider_event_unique');
            $table->index(['gift_card_submission_id', 'operation', 'created_at'], 'gift_card_provider_submission_op_idx');
        });
        DB::statement("ALTER TABLE gift_card_provider_events ADD CONSTRAINT gift_card_provider_operation_chk CHECK (`operation` IN ('validate','reserve','redeem','release','status','webhook'))");
        DB::statement("ALTER TABLE gift_card_provider_events ADD CONSTRAINT gift_card_provider_outcome_chk CHECK (`outcome` IN ('success','pending','rejected','uncertain','unavailable'))");
        DB::statement('ALTER TABLE gift_card_provider_events ADD CONSTRAINT gift_card_provider_hash_chk CHECK (CHAR_LENGTH(`evidence_hash`) = 64)');

        Schema::create('gift_card_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('gift_card_submission_id')->unique()->constrained('gift_card_submissions')->restrictOnDelete();
            $table->foreignId('provider_event_row_id')->unique()->constrained('gift_card_provider_events')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_redemption_id', 191);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3)->default('IRR');
            $table->char('evidence_hash', 64);
            $table->foreignId('purchase_settlement_id')->nullable()->unique()->constrained('purchase_settlements')->restrictOnDelete();
            $table->dateTime('redeemed_at', 6);
            $table->dateTime('created_at', 6);
            $table->unique(['provider_code', 'provider_redemption_id'], 'gift_card_redemption_provider_unique');
        });
        DB::statement('ALTER TABLE gift_card_redemptions ADD CONSTRAINT gift_card_redemption_amount_chk CHECK (`amount_irr` > 0 AND `currency` = \'IRR\' AND CHAR_LENGTH(`evidence_hash`) = 64)');

        Schema::create('gift_card_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('gift_card_submission_id')->unique()->constrained('gift_card_submissions')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->string('state', 16)->default('pending');
            $table->foreignId('decided_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('decision_reason', 191)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
        DB::statement("ALTER TABLE gift_card_reviews ADD CONSTRAINT gift_card_review_state_chk CHECK (`state` IN ('pending','approved','rejected'))");

        $this->createGuards();
    }

    public function down(): void
    {
        if (DB::table('gift_card_submissions')->exists()) {
            throw new RuntimeException('Cannot roll back gift-card payment foundation while gift-card evidence exists.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_redemptions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_redemptions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_provider_events_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_provider_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_delete_guard');
        Schema::dropIfExists('gift_card_reviews');
        Schema::dropIfExists('gift_card_redemptions');
        Schema::dropIfExists('gift_card_provider_events');
        Schema::dropIfExists('gift_card_submissions');
        Schema::dropIfExists('gift_card_types');
    }

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_submissions_delete_guard
BEFORE DELETE ON gift_card_submissions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card submissions are non-deletable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_provider_events_update_guard
BEFORE UPDATE ON gift_card_provider_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card provider events are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_provider_events_delete_guard
BEFORE DELETE ON gift_card_provider_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card provider events are non-deletable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_redemptions_update_guard
BEFORE UPDATE ON gift_card_redemptions
FOR EACH ROW
BEGIN
    IF NOT (NEW.purchase_settlement_id <=> OLD.purchase_settlement_id)
       AND OLD.purchase_settlement_id IS NULL
       AND NEW.purchase_settlement_id IS NOT NULL THEN
        -- one-time settlement linkage is allowed; all financial identity remains immutable.
        IF NOT (NEW.public_id <=> OLD.public_id)
           OR NOT (NEW.gift_card_submission_id <=> OLD.gift_card_submission_id)
           OR NOT (NEW.provider_event_row_id <=> OLD.provider_event_row_id)
           OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
           OR NOT (NEW.provider_code <=> OLD.provider_code)
           OR NOT (NEW.provider_redemption_id <=> OLD.provider_redemption_id)
           OR NOT (NEW.amount_irr <=> OLD.amount_irr)
           OR NOT (NEW.currency <=> OLD.currency)
           OR NOT (NEW.evidence_hash <=> OLD.evidence_hash)
           OR NOT (NEW.redeemed_at <=> OLD.redeemed_at)
           OR NOT (NEW.created_at <=> OLD.created_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card redemption financial identity is immutable.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card redemptions are immutable except one-time settlement linkage.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_redemptions_delete_guard
BEFORE DELETE ON gift_card_redemptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card redemptions are non-deletable.';
END
SQL);
    }
};
