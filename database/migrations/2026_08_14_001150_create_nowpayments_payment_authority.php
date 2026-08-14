<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement IPG-002 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-004 */
    public function up(): void
    {
        Schema::create('nowpayments_payment_authorities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('request_key', 128)->unique();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->string('order_id', 128)->unique();
            $table->string('state', 32);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->string('rate_source', 32);
            $table->decimal('rate_irr', 28, 8);
            $table->dateTime('rate_fetched_at', 6);
            $table->char('rate_response_hash', 64);
            $table->string('pricing_policy_code', 64);
            $table->decimal('price_amount_usd', 30, 8);
            $table->char('price_currency', 3);
            $table->string('pay_currency', 32);
            $table->string('callback_url', 512);
            $table->char('request_payload_hash', 64);
            $table->string('provider_payment_id', 64)->nullable()->unique();
            $table->string('provider_status', 32)->nullable();
            $table->string('provider_pay_amount', 96)->nullable();
            $table->string('provider_actually_paid', 96)->nullable();
            $table->string('provider_pay_address', 256)->nullable();
            $table->char('create_response_hash', 64)->nullable();
            $table->dateTime('create_attempted_at', 6);
            $table->dateTime('provider_created_at', 6)->nullable();
            $table->dateTime('last_status_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['state', 'updated_at'], 'nowpayments_authority_state_idx');
        });
        DB::statement("ALTER TABLE nowpayments_payment_authorities ADD CONSTRAINT nowpayments_authority_state_chk CHECK (`state` IN ('initiating','created','uncertain','manual_review','finished','failed','expired'))");
        DB::statement("ALTER TABLE nowpayments_payment_authorities ADD CONSTRAINT nowpayments_authority_money_chk CHECK (`amount_irr` > 0 AND `currency` = 'IRR' AND `rate_irr` > 0 AND `price_amount_usd` > 0 AND `price_currency` = 'USD')");
        DB::statement('ALTER TABLE nowpayments_payment_authorities ADD CONSTRAINT nowpayments_authority_hashes_chk CHECK (CHAR_LENGTH(`rate_response_hash`) = 64 AND CHAR_LENGTH(`request_payload_hash`) = 64 AND (`create_response_hash` IS NULL OR CHAR_LENGTH(`create_response_hash`) = 64))');
        DB::statement("ALTER TABLE nowpayments_payment_authorities ADD CONSTRAINT nowpayments_authority_provider_shape_chk CHECK ((`state` IN ('initiating','uncertain') AND (`provider_payment_id` IS NULL OR `create_response_hash` IS NOT NULL)) OR (`state` IN ('created','manual_review','finished','failed','expired') AND `provider_payment_id` IS NOT NULL AND `provider_status` IS NOT NULL AND `create_response_hash` IS NOT NULL))");

        Schema::create('nowpayments_payment_observations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('nowpayments_payment_authority_id');
            $table->foreign('nowpayments_payment_authority_id', 'nowpayments_observation_authority_fk')
                ->references('id')
                ->on('nowpayments_payment_authorities')
                ->restrictOnDelete();
            $table->string('event_key', 191)->unique();
            $table->string('event_type', 32);
            $table->string('provider_payment_id', 64)->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->char('response_hash', 64);
            $table->dateTime('occurred_at', 6);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['nowpayments_payment_authority_id', 'id'], 'nowpayments_observation_authority_idx');
        });
        DB::statement("ALTER TABLE nowpayments_payment_observations ADD CONSTRAINT nowpayments_observation_type_chk CHECK (`event_type` IN ('create_response','create_uncertain','status_lookup','ipn_received','provider_unavailable','mismatch','manual_review'))");
        DB::statement('ALTER TABLE nowpayments_payment_observations ADD CONSTRAINT nowpayments_observation_hash_chk CHECK (CHAR_LENGTH(`response_hash`) = 64)');

        $this->createAuthorityGuards();
        $this->createObservationGuards();
        $this->createSettlementGuard();
    }

    public function down(): void
    {
        if (DB::table('nowpayments_payment_observations')->exists()
            || DB::table('nowpayments_payment_authorities')->exists()) {
            throw new RuntimeException('Cannot roll back NOWPayments authority after provider state exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_purchase_settlement_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_observation_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_observation_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_observation_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_authority_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_authority_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_authority_insert_guard');
        Schema::dropIfExists('nowpayments_payment_observations');
        Schema::dropIfExists('nowpayments_payment_authorities');
    }

    private function createAuthorityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_authority_insert_guard
BEFORE INSERT ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    DECLARE matching_intent_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.provider_code = 'nowpayments'
      AND intent_row.amount_irr = NEW.amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.state = 'created'
      AND intent_row.captured_at IS NULL;

    IF matching_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority requires one matching created purchase payment intent.';
    END IF;
    IF NEW.state <> 'initiating' OR NEW.provider_payment_id IS NOT NULL OR NEW.provider_status IS NOT NULL OR NEW.create_response_hash IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority must start in initiating state before provider mutation.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_authority_update_guard
BEFORE UPDATE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.request_key <=> OLD.request_key)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.order_id <=> OLD.order_id)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.rate_source <=> OLD.rate_source)
       OR NOT (NEW.rate_irr <=> OLD.rate_irr)
       OR NOT (NEW.rate_fetched_at <=> OLD.rate_fetched_at)
       OR NOT (NEW.rate_response_hash <=> OLD.rate_response_hash)
       OR NOT (NEW.pricing_policy_code <=> OLD.pricing_policy_code)
       OR NOT (NEW.price_amount_usd <=> OLD.price_amount_usd)
       OR NOT (NEW.price_currency <=> OLD.price_currency)
       OR NOT (NEW.pay_currency <=> OLD.pay_currency)
       OR NOT (NEW.callback_url <=> OLD.callback_url)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash)
       OR NOT (NEW.create_attempted_at <=> OLD.create_attempted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments pricing/request authority is immutable.';
    END IF;

    IF OLD.provider_payment_id IS NOT NULL AND NOT (NEW.provider_payment_id <=> OLD.provider_payment_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider payment ID is immutable once accepted.';
    END IF;
    IF OLD.create_response_hash IS NOT NULL AND NOT (NEW.create_response_hash <=> OLD.create_response_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments create response identity is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_amount IS NOT NULL AND NOT (NEW.provider_pay_amount <=> OLD.provider_pay_amount) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay amount is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_address IS NOT NULL AND NOT (NEW.provider_pay_address <=> OLD.provider_pay_address) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay address is immutable once accepted.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'initiating' AND NEW.state IN ('created','uncertain','failed')) OR
        (OLD.state = 'uncertain' AND NEW.state = 'manual_review') OR
        (OLD.state = 'created' AND NEW.state IN ('manual_review','finished','failed','expired')) OR
        (OLD.state = 'manual_review' AND NEW.state IN ('finished','failed','expired'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority state transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_authority_delete_guard
BEFORE DELETE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments payment authority is non-deletable.';
END
SQL);
    }

    private function createObservationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_observation_insert_guard
BEFORE INSERT ON nowpayments_payment_observations
FOR EACH ROW
BEGIN
    DECLARE authority_count INT DEFAULT 0;

    SELECT COUNT(*) INTO authority_count
    FROM nowpayments_payment_authorities authority_row
    WHERE authority_row.id = NEW.nowpayments_payment_authority_id;

    IF authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments observation requires existing authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_observation_update_guard
BEFORE UPDATE ON nowpayments_payment_observations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments payment observations are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_observation_delete_guard
BEFORE DELETE ON nowpayments_payment_observations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments payment observations are non-deletable.';
END
SQL);
    }

    private function createSettlementGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_purchase_settlement_guard
BEFORE INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    DECLARE authority_count INT DEFAULT 0;

    IF NEW.provider_code = 'nowpayments' THEN
        SELECT COUNT(*) INTO authority_count
        FROM nowpayments_payment_authorities authority_row
        WHERE authority_row.payment_intent_id = NEW.payment_intent_id
          AND authority_row.state = 'finished'
          AND authority_row.provider_status = 'finished'
          AND authority_row.provider_payment_id = NEW.provider_transaction_id
          AND authority_row.amount_irr = NEW.amount_irr
          AND authority_row.currency = NEW.currency
          AND authority_row.price_currency = 'USD'
          AND authority_row.provider_pay_amount IS NOT NULL
          AND authority_row.provider_actually_paid IS NOT NULL
          AND authority_row.provider_pay_amount = authority_row.provider_actually_paid;

        IF authority_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments settlement requires exact finished provider authority.';
        END IF;
    END IF;
END
SQL);
    }
};
