<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement C2C-003 C2C-004 C2C-005 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-004 */
    public function up(): void
    {
        Schema::create('c2c_bank_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('provider_code', 64);
            $table->string('provider_transaction_id', 128);
            $table->foreignId('c2c_destination_account_id')->constrained('c2c_destination_accounts')->restrictOnDelete();
            $table->bigInteger('amount_irr');
            $table->char('currency', 3)->default('IRR');
            $table->string('status', 16);
            $table->dateTime('occurred_at', 6);
            $table->char('sender_card_lookup_hash', 64)->nullable();
            $table->text('encrypted_sender_name')->nullable();
            $table->string('reference', 191)->nullable();
            $table->char('first_evidence_payload_hash', 64);
            $table->dateTime('first_observed_at', 6);
            $table->dateTime('last_observed_at', 6);
            $table->dateTime('created_at', 6);
            $table->unique(['provider_code', 'provider_transaction_id'], 'c2c_bank_provider_tx_unique');
            $table->index(['c2c_destination_account_id', 'amount_irr', 'occurred_at'], 'c2c_bank_match_lookup_idx');
            $table->index(['status', 'occurred_at'], 'c2c_bank_status_time_idx');
        });
        DB::statement("ALTER TABLE c2c_bank_transactions ADD CONSTRAINT c2c_bank_transaction_state_chk CHECK (`status` IN ('pending','settled','reversed','failed'))");
        DB::statement("ALTER TABLE c2c_bank_transactions ADD CONSTRAINT c2c_bank_transaction_money_chk CHECK (`amount_irr` > 0 AND `currency` = 'IRR')");
        DB::statement('ALTER TABLE c2c_bank_transactions ADD CONSTRAINT c2c_bank_transaction_hash_chk CHECK (CHAR_LENGTH(`first_evidence_payload_hash`) = 64 AND (`sender_card_lookup_hash` IS NULL OR CHAR_LENGTH(`sender_card_lookup_hash`) = 64))');

        Schema::create('c2c_bank_transaction_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('c2c_bank_transaction_id')->constrained('c2c_bank_transactions')->restrictOnDelete();
            $table->string('provider_event_id', 128);
            $table->string('status', 16);
            $table->char('evidence_payload_hash', 64);
            $table->string('ingestion_method', 16);
            $table->dateTime('observed_at', 6);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['c2c_bank_transaction_id', 'provider_event_id'], 'c2c_bank_event_unique');
            $table->index(['c2c_bank_transaction_id', 'id'], 'c2c_bank_event_order_idx');
        });
        DB::statement("ALTER TABLE c2c_bank_transaction_events ADD CONSTRAINT c2c_bank_event_state_chk CHECK (`status` IN ('pending','settled','reversed','failed'))");
        DB::statement("ALTER TABLE c2c_bank_transaction_events ADD CONSTRAINT c2c_bank_ingestion_chk CHECK (`ingestion_method` IN ('poll','webhook','manual','fake'))");
        DB::statement('ALTER TABLE c2c_bank_transaction_events ADD CONSTRAINT c2c_bank_event_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');

        Schema::create('c2c_provider_cursors', function (Blueprint $table): void {
            $table->string('provider_code', 64)->primary();
            $table->string('cursor', 512)->nullable();
            $table->dateTime('last_success_at', 6)->nullable();
            $table->dateTime('last_failure_at', 6)->nullable();
            $table->string('last_failure_code', 64)->nullable();
            $table->dateTime('updated_at', 6);
        });

        $this->createTransactionGuards();
        $this->createEventGuards();
    }

    public function down(): void
    {
        if (DB::table('c2c_bank_transaction_events')->exists()
            || DB::table('c2c_bank_transactions')->exists()) {
            throw new RuntimeException('Cannot roll back C2C bank transaction authority after evidence exists.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_bank_transaction_events_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_bank_transaction_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_bank_transactions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS c2c_bank_transactions_update_guard');
        Schema::dropIfExists('c2c_provider_cursors');
        Schema::dropIfExists('c2c_bank_transaction_events');
        Schema::dropIfExists('c2c_bank_transactions');
    }

    private function createTransactionGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_bank_transactions_update_guard
BEFORE UPDATE ON c2c_bank_transactions
FOR EACH ROW
BEGIN
    DECLARE matching_event_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.provider_transaction_id <=> OLD.provider_transaction_id)
       OR NOT (NEW.c2c_destination_account_id <=> OLD.c2c_destination_account_id)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.occurred_at <=> OLD.occurred_at)
       OR NOT (NEW.sender_card_lookup_hash <=> OLD.sender_card_lookup_hash)
       OR NOT (NEW.encrypted_sender_name <=> OLD.encrypted_sender_name)
       OR NOT (NEW.reference <=> OLD.reference)
       OR NOT (NEW.first_evidence_payload_hash <=> OLD.first_evidence_payload_hash)
       OR NOT (NEW.first_observed_at <=> OLD.first_observed_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction financial identity is immutable.';
    END IF;

    IF NEW.status <> OLD.status THEN
        IF NOT ((OLD.status = 'pending' AND NEW.status IN ('settled','failed','reversed')) OR (OLD.status = 'settled' AND NEW.status = 'reversed')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction status transition is invalid.';
        END IF;
        SELECT COUNT(*) INTO matching_event_count
        FROM c2c_bank_transaction_events event_row
        WHERE event_row.c2c_bank_transaction_id = OLD.id
          AND event_row.status = NEW.status
          AND event_row.observed_at = NEW.last_observed_at;
        IF matching_event_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction status transition requires immutable event authority.';
        END IF;
    ELSEIF NOT (NEW.last_observed_at <=> OLD.last_observed_at) THEN
        SELECT COUNT(*) INTO matching_event_count
        FROM c2c_bank_transaction_events event_row
        WHERE event_row.c2c_bank_transaction_id = OLD.id
          AND event_row.status = NEW.status
          AND event_row.observed_at = NEW.last_observed_at;
        IF matching_event_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction observation timestamp requires event authority.';
        END IF;
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_bank_transactions_delete_guard
BEFORE DELETE ON c2c_bank_transactions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transactions are non-deletable.';
END
SQL);
    }

    private function createEventGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_bank_transaction_events_update_guard
BEFORE UPDATE ON c2c_bank_transaction_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction events are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER c2c_bank_transaction_events_delete_guard
BEFORE DELETE ON c2c_bank_transaction_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C2C bank transaction events are non-deletable.';
END
SQL);
    }
};
