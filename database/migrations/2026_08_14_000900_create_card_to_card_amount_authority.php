<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement C2C-001 C2C-002 C2C-004 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('c2c_destination_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->text('encrypted_card_number');
            $table->char('card_lookup_hash', 64)->unique();
            $table->string('masked_card_number', 32);
            $table->text('encrypted_account_holder_name')->nullable();
            $table->string('state', 16)->default('active');
            $table->boolean('adjustment_enabled')->default(true);
            $table->unsignedInteger('adjustment_min_irr')->default(1000);
            $table->unsignedInteger('adjustment_max_irr')->default(9990);
            $table->unsignedSmallInteger('reservation_minutes')->default(30);
            $table->unsignedSmallInteger('late_review_minutes')->default(1440);
            $table->bigInteger('daily_limit_irr')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('verification_provider_code', 64)->default('manual');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['state', 'priority', 'id'], 'c2c_destination_selection_idx');
        });
        DB::statement("ALTER TABLE c2c_destination_accounts ADD CONSTRAINT c2c_destination_state_chk CHECK (`state` IN ('active','inactive'))");
        DB::statement('ALTER TABLE c2c_destination_accounts ADD CONSTRAINT c2c_destination_hash_chk CHECK (CHAR_LENGTH(`card_lookup_hash`) = 64)');
        DB::statement('ALTER TABLE c2c_destination_accounts ADD CONSTRAINT c2c_destination_adjustment_chk CHECK ((`adjustment_enabled` = 0 AND `adjustment_min_irr` = 0 AND `adjustment_max_irr` = 0) OR (`adjustment_enabled` = 1 AND `adjustment_min_irr` >= 0 AND `adjustment_max_irr` >= `adjustment_min_irr` AND `adjustment_max_irr` <= 999999))');
        DB::statement('ALTER TABLE c2c_destination_accounts ADD CONSTRAINT c2c_destination_window_chk CHECK (`reservation_minutes` BETWEEN 1 AND 1440 AND `late_review_minutes` BETWEEN `reservation_minutes` AND 10080)');
        DB::statement('ALTER TABLE c2c_destination_accounts ADD CONSTRAINT c2c_destination_limit_chk CHECK (`daily_limit_irr` IS NULL OR `daily_limit_irr` > 0)');

        Schema::create('c2c_destination_account_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('c2c_destination_account_id')->constrained('c2c_destination_accounts')->restrictOnDelete();
            $table->string('event_type', 16);
            $table->char('configuration_hash', 64);
            $table->string('reason', 191);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['c2c_destination_account_id', 'id'], 'c2c_destination_event_account_idx');
        });
        DB::statement("ALTER TABLE c2c_destination_account_events ADD CONSTRAINT c2c_destination_event_type_chk CHECK (`event_type` IN ('created','activated','deactivated','configured'))");
        DB::statement('ALTER TABLE c2c_destination_account_events ADD CONSTRAINT c2c_destination_event_hash_chk CHECK (CHAR_LENGTH(`configuration_hash`) = 64)');

        Schema::create('c2c_amount_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('c2c_destination_account_id')->constrained('c2c_destination_accounts')->restrictOnDelete();
            $table->bigInteger('base_amount_irr');
            $table->unsignedInteger('adjustment_amount_irr');
            $table->bigInteger('payable_amount_irr');
            $table->unsignedTinyInteger('active_lock')->nullable()->default(1);
            $table->dateTime('reserved_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('late_review_until', 6);
            $table->dateTime('released_at', 6)->nullable();
            $table->string('release_reason', 32)->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['c2c_destination_account_id', 'payable_amount_irr', 'active_lock'], 'c2c_active_payable_unique');
            $table->index(['c2c_destination_account_id', 'expires_at', 'active_lock'], 'c2c_reservation_expiry_idx');
            $table->index(['payable_amount_irr', 'expires_at'], 'c2c_reservation_match_amount_idx');
        });
        DB::statement('ALTER TABLE c2c_amount_reservations ADD CONSTRAINT c2c_reservation_amount_chk CHECK (`base_amount_irr` > 0 AND `adjustment_amount_irr` >= 0 AND `payable_amount_irr` = `base_amount_irr` + `adjustment_amount_irr`)');
        DB::statement('ALTER TABLE c2c_amount_reservations ADD CONSTRAINT c2c_reservation_window_chk CHECK (`expires_at` > `reserved_at` AND `late_review_until` >= `expires_at`)');
        DB::statement("ALTER TABLE c2c_amount_reservations ADD CONSTRAINT c2c_reservation_active_shape_chk CHECK ((`active_lock` = 1 AND `released_at` IS NULL AND `release_reason` IS NULL) OR (`active_lock` IS NULL AND `released_at` IS NOT NULL AND `release_reason` IN ('expired','matched','canceled','manual_review'))) ");

        $this->createDestinationGuards();
        $this->createReservationGuards();
    }

    public function down(): void
    {
        if (DB::table('c2c_amount_reservations')->exists()
            || DB::table('c2c_destination_account_events')->exists()
            || DB::table('c2c_destination_accounts')->exists()) {
            throw new RuntimeException('Cannot roll back card-to-card authority after destination/reservation state exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS c2c_amount_reservations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_amount_reservations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_amount_reservations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_destination_account_events_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_destination_account_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_destination_accounts_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_destination_accounts_update_guard');
        Schema::dropIfExists('c2c_amount_reservations');
        Schema::dropIfExists('c2c_destination_account_events');
        Schema::dropIfExists('c2c_destination_accounts');
    }

    private function createDestinationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_destination_accounts_update_guard
BEFORE UPDATE ON c2c_destination_accounts
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.code <=> OLD.code)
       OR NOT (NEW.encrypted_card_number <=> OLD.encrypted_card_number)
       OR NOT (NEW.card_lookup_hash <=> OLD.card_lookup_hash)
       OR NOT (NEW.masked_card_number <=> OLD.masked_card_number)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card destination financial identity is immutable.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_destination_accounts_delete_guard
BEFORE DELETE ON c2c_destination_accounts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card destinations are non-deletable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_destination_account_events_update_guard
BEFORE UPDATE ON c2c_destination_account_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card destination events are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_destination_account_events_delete_guard
BEFORE DELETE ON c2c_destination_account_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card destination events are non-deletable.';
END
SQL);
    }

    private function createReservationGuards(): void
    {
        DB::unprepared(<<<'SQL'
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
      AND intent_row.state = 'created'
      AND intent_row.captured_at IS NULL;
    IF valid_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card reservation requires one matching created purchase payment intent.';
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_amount_reservations_update_guard
BEFORE UPDATE ON c2c_amount_reservations
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.c2c_destination_account_id <=> OLD.c2c_destination_account_id)
       OR NOT (NEW.base_amount_irr <=> OLD.base_amount_irr)
       OR NOT (NEW.adjustment_amount_irr <=> OLD.adjustment_amount_irr)
       OR NOT (NEW.payable_amount_irr <=> OLD.payable_amount_irr)
       OR NOT (NEW.reserved_at <=> OLD.reserved_at)
       OR NOT (NEW.expires_at <=> OLD.expires_at)
       OR NOT (NEW.late_review_until <=> OLD.late_review_until)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card reservation financial identity is immutable.';
    END IF;

    IF OLD.active_lock IS NULL THEN
        IF NOT (NEW.active_lock <=> OLD.active_lock)
           OR NOT (NEW.released_at <=> OLD.released_at)
           OR NOT (NEW.release_reason <=> OLD.release_reason) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Released card-to-card reservation is immutable.';
        END IF;
    ELSEIF NOT (NEW.active_lock <=> OLD.active_lock) THEN
        IF NEW.active_lock IS NOT NULL OR NEW.released_at IS NULL OR NEW.release_reason IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card reservation release transition is invalid.';
        END IF;
    ELSEIF NOT (NEW.released_at <=> OLD.released_at) OR NOT (NEW.release_reason <=> OLD.release_reason) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card reservation release fields require release transition.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_amount_reservations_delete_guard
BEFORE DELETE ON c2c_amount_reservations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card amount reservations are non-deletable.';
END
SQL);
    }
};
