<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement IPG-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-004 */
    public function up(): void
    {
        Schema::create('zarinpal_payment_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('request_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->char('merchant_configuration_hash', 64);
            $table->string('authority', 64)->nullable()->unique();
            $table->string('state', 32);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->string('callback_url', 512);
            $table->integer('request_provider_code')->nullable();
            $table->dateTime('request_attempted_at', 6);
            $table->dateTime('authority_received_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['state', 'updated_at'], 'zarinpal_request_state_updated_idx');
        });
        DB::statement('ALTER TABLE zarinpal_payment_requests ADD CONSTRAINT zarinpal_request_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64 AND CHAR_LENGTH(`merchant_configuration_hash`) = 64)');
        DB::statement("ALTER TABLE zarinpal_payment_requests ADD CONSTRAINT zarinpal_request_state_chk CHECK (`state` IN ('initiating','redirectable','uncertain','failed','verified','manual_review'))");
        DB::statement("ALTER TABLE zarinpal_payment_requests ADD CONSTRAINT zarinpal_request_money_chk CHECK (`amount_irr` > 0 AND `currency` = 'IRR')");
        DB::statement("ALTER TABLE zarinpal_payment_requests ADD CONSTRAINT zarinpal_request_authority_shape_chk CHECK ((`state` = 'initiating' AND `authority` IS NULL AND `authority_received_at` IS NULL) OR (`state` IN ('uncertain','failed') AND (`authority` IS NULL OR `authority_received_at` IS NOT NULL)) OR (`state` IN ('redirectable','verified','manual_review') AND `authority` IS NOT NULL AND `authority_received_at` IS NOT NULL))");

        Schema::create('zarinpal_payment_verifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('zarinpal_payment_request_id')->unique()->constrained('zarinpal_payment_requests')->restrictOnDelete();
            $table->foreignId('purchase_settlement_id')->unique()->constrained('purchase_settlements')->restrictOnDelete();
            $table->string('authority', 64)->unique();
            $table->string('provider_ref_id', 64)->unique();
            $table->integer('provider_verify_code');
            $table->char('evidence_payload_hash', 64);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->dateTime('verified_at', 6);
            $table->dateTime('provider_reverse_eligible_until', 6);
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE zarinpal_payment_verifications ADD CONSTRAINT zarinpal_verification_code_chk CHECK (`provider_verify_code` IN (100,101))");
        DB::statement("ALTER TABLE zarinpal_payment_verifications ADD CONSTRAINT zarinpal_verification_money_chk CHECK (`amount_irr` > 0 AND `currency` = 'IRR')");
        DB::statement('ALTER TABLE zarinpal_payment_verifications ADD CONSTRAINT zarinpal_verification_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');
        DB::statement('ALTER TABLE zarinpal_payment_verifications ADD CONSTRAINT zarinpal_verification_reverse_window_chk CHECK (`provider_reverse_eligible_until` >= `verified_at`)');

        Schema::create('zarinpal_payment_observations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('zarinpal_payment_request_id')->constrained('zarinpal_payment_requests')->restrictOnDelete();
            $table->string('event_key', 191)->unique();
            $table->string('event_type', 32);
            $table->string('provider_status', 32)->nullable();
            $table->integer('provider_code')->nullable();
            $table->unsignedSmallInteger('candidate_count')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['zarinpal_payment_request_id', 'id'], 'zarinpal_observation_request_idx');
        });
        DB::statement("ALTER TABLE zarinpal_payment_observations ADD CONSTRAINT zarinpal_observation_type_chk CHECK (`event_type` IN ('request_accepted','request_rejected','request_uncertain','callback_ok','callback_nok','verify_rejected','verify_uncertain','inquiry_verified','inquiry_paid','inquiry_in_bank','inquiry_failed','inquiry_reversed','inquiry_unavailable','unverified_discovery','manual_review'))");

        $this->createRequestGuards();
        $this->createVerificationGuards();
        $this->createObservationGuards();
    }

    public function down(): void
    {
        if (DB::table('zarinpal_payment_verifications')->exists()
            || DB::table('zarinpal_payment_observations')->exists()
            || DB::table('zarinpal_payment_requests')->exists()) {
            throw new RuntimeException('Cannot roll back Zarinpal payment authority after provider state exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_observations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_observations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_observations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_verifications_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_verifications_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_verifications_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_requests_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_requests_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_requests_insert_guard');
        Schema::dropIfExists('zarinpal_payment_observations');
        Schema::dropIfExists('zarinpal_payment_verifications');
        Schema::dropIfExists('zarinpal_payment_requests');
    }

    private function createRequestGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_requests_insert_guard
BEFORE INSERT ON zarinpal_payment_requests
FOR EACH ROW
BEGIN
    DECLARE matching_intent_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.provider_code = 'zarinpal'
      AND intent_row.amount_irr = NEW.amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.state = 'created'
      AND intent_row.captured_at IS NULL;

    IF matching_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request requires one matching created purchase payment intent.';
    END IF;
    IF NEW.state <> 'initiating' OR NEW.authority IS NOT NULL OR NEW.authority_received_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request must start in initiating state without authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_requests_update_guard
BEFORE UPDATE ON zarinpal_payment_requests
FOR EACH ROW
BEGIN
    DECLARE verification_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.request_key <=> OLD.request_key)
       OR NOT (NEW.payload_hash <=> OLD.payload_hash)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.merchant_configuration_hash <=> OLD.merchant_configuration_hash)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.callback_url <=> OLD.callback_url)
       OR NOT (NEW.request_attempted_at <=> OLD.request_attempted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request financial/configuration identity is immutable.';
    END IF;

    IF OLD.authority IS NOT NULL AND NOT (NEW.authority <=> OLD.authority) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal authority is immutable once accepted.';
    END IF;
    IF OLD.authority_received_at IS NOT NULL AND NOT (NEW.authority_received_at <=> OLD.authority_received_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal authority receipt timestamp is immutable.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'initiating' AND NEW.state IN ('redirectable','uncertain','failed')) OR
        (OLD.state = 'redirectable' AND NEW.state IN ('verified','failed','manual_review')) OR
        (OLD.state = 'uncertain' AND NEW.state = 'manual_review') OR
        (OLD.state = 'manual_review' AND NEW.state IN ('verified','failed')) OR
        (OLD.state = 'verified' AND NEW.state = 'manual_review')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request state transition is invalid.';
    END IF;

    IF OLD.state = 'initiating' AND NEW.state = 'redirectable' THEN
        IF NEW.authority IS NULL OR NEW.authority_received_at IS NULL OR NEW.request_provider_code <> 100 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accepted Zarinpal request requires authority and provider success code.';
        END IF;
    END IF;

    IF NEW.state = 'verified' AND OLD.state <> 'verified' THEN
        SELECT COUNT(*) INTO verification_count
        FROM zarinpal_payment_verifications verification_row
        WHERE verification_row.zarinpal_payment_request_id = NEW.id
          AND verification_row.authority = NEW.authority
          AND verification_row.amount_irr = NEW.amount_irr
          AND verification_row.currency = NEW.currency;
        IF verification_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verified Zarinpal request requires immutable verification authority.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_requests_delete_guard
BEFORE DELETE ON zarinpal_payment_requests
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal payment requests are non-deletable.';
END
SQL);
    }

    private function createVerificationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_verifications_insert_guard
BEFORE INSERT ON zarinpal_payment_verifications
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_authority_count
    FROM zarinpal_payment_requests request_row
    INNER JOIN payment_intents intent_row ON intent_row.id = request_row.payment_intent_id
    INNER JOIN purchase_settlements settlement_row ON settlement_row.payment_intent_id = intent_row.id
    WHERE request_row.id = NEW.zarinpal_payment_request_id
      AND request_row.state IN ('redirectable','manual_review')
      AND request_row.authority = NEW.authority
      AND request_row.amount_irr = NEW.amount_irr
      AND request_row.currency = NEW.currency
      AND request_row.merchant_configuration_hash IS NOT NULL
      AND intent_row.purpose = 'purchase'
      AND intent_row.provider_code = 'zarinpal'
      AND intent_row.amount_irr = NEW.amount_irr
      AND intent_row.currency = NEW.currency
      AND settlement_row.id = NEW.purchase_settlement_id
      AND settlement_row.provider_code = 'zarinpal'
      AND settlement_row.provider_transaction_id = NEW.provider_ref_id
      AND settlement_row.amount_irr = NEW.amount_irr
      AND settlement_row.currency = NEW.currency;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal verification must match request, intent and authoritative purchase settlement.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_verifications_update_guard
BEFORE UPDATE ON zarinpal_payment_verifications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal payment verifications are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_verifications_delete_guard
BEFORE DELETE ON zarinpal_payment_verifications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal payment verifications are non-deletable.';
END
SQL);
    }

    private function createObservationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_observations_insert_guard
BEFORE INSERT ON zarinpal_payment_observations
FOR EACH ROW
BEGIN
    DECLARE request_count INT DEFAULT 0;

    SELECT COUNT(*) INTO request_count
    FROM zarinpal_payment_requests
    WHERE id = NEW.zarinpal_payment_request_id;
    IF request_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal observation requires an existing request.';
    END IF;

    IF NEW.event_type = 'unverified_discovery' AND NEW.candidate_count IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal unverified discovery observation requires candidate count.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_observations_update_guard
BEFORE UPDATE ON zarinpal_payment_observations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal payment observations are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_observations_delete_guard
BEFORE DELETE ON zarinpal_payment_observations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal payment observations are non-deletable.';
END
SQL);
    }
};
