<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement USDT-003 PAY-002 PAY-003 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
    public function up(): void
    {
        Schema::create('usdt_payment_authorities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('authority_key', 128)->unique();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('usdt_amount_quote_id')->unique()->constrained('usdt_amount_quotes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_quote_id')->constrained('quotes')->restrictOnDelete();
            $table->ulid('source_quote_public_id');
            $table->bigInteger('source_amount_irr');
            $table->foreignId('destination_wallet_version_id')->constrained('usdt_destination_wallet_versions')->restrictOnDelete();
            $table->string('destination_address', 42);
            $table->string('network', 16);
            $table->unsignedInteger('chain_id');
            $table->string('token_contract', 42);
            $table->unsignedBigInteger('expected_amount_base_units');
            $table->unsignedSmallInteger('minimum_confirmations');
            $table->dateTime('quote_expires_at', 6);
            $table->char('destination_configuration_hash', 64);
            $table->char('amount_quote_configuration_hash', 64);
            $table->char('policy_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE usdt_payment_authorities ADD CONSTRAINT usdt_payment_authority_network_chk CHECK (`network` = 'BEP20')");
        DB::statement('ALTER TABLE usdt_payment_authorities ADD CONSTRAINT usdt_payment_authority_chain_chk CHECK (`chain_id` = 56)');
        DB::statement("ALTER TABLE usdt_payment_authorities ADD CONSTRAINT usdt_payment_authority_address_chk CHECK (`destination_address` REGEXP '^0x[a-f0-9]{40}$' AND `token_contract` REGEXP '^0x[a-f0-9]{40}$')");
        DB::statement('ALTER TABLE usdt_payment_authorities ADD CONSTRAINT usdt_payment_authority_amount_chk CHECK (`source_amount_irr` > 0 AND `expected_amount_base_units` > 0)');
        DB::statement('ALTER TABLE usdt_payment_authorities ADD CONSTRAINT usdt_payment_authority_confirmations_chk CHECK (`minimum_confirmations` BETWEEN 1 AND 1000)');
        DB::statement('ALTER TABLE usdt_payment_authorities ADD CONSTRAINT usdt_payment_authority_hash_chk CHECK (CHAR_LENGTH(`destination_configuration_hash`) = 64 AND CHAR_LENGTH(`amount_quote_configuration_hash`) = 64 AND CHAR_LENGTH(`policy_snapshot_hash`) = 64)');

        Schema::create('usdt_txid_submissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('submission_key', 128)->unique();
            $table->foreignId('usdt_payment_authority_id')->unique()->constrained('usdt_payment_authorities')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('txid', 66)->unique();
            $table->string('private_evidence_reference', 191)->nullable();
            $table->char('evidence_content_hash', 64)->nullable();
            $table->string('state', 32);
            $table->char('request_payload_hash', 64);
            $table->dateTime('submitted_at', 6);
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE usdt_txid_submissions ADD CONSTRAINT usdt_txid_shape_chk CHECK (`txid` REGEXP '^0x[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE usdt_txid_submissions ADD CONSTRAINT usdt_submission_state_chk CHECK (`state` IN ('submitted','verifying','pending_manual_review','verified','captured','provider_unavailable','rejected'))");
        DB::statement('ALTER TABLE usdt_txid_submissions ADD CONSTRAINT usdt_submission_hash_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND (`evidence_content_hash` IS NULL OR CHAR_LENGTH(`evidence_content_hash`) = 64))');
        DB::statement('ALTER TABLE usdt_txid_submissions ADD CONSTRAINT usdt_submission_evidence_shape_chk CHECK ((`private_evidence_reference` IS NULL AND `evidence_content_hash` IS NULL) OR (`private_evidence_reference` IS NOT NULL AND `evidence_content_hash` IS NOT NULL))');

        Schema::create('usdt_chain_verification_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('usdt_txid_submission_id')->constrained('usdt_txid_submissions')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_event_id', 191);
            $table->char('txid', 66);
            $table->string('outcome', 16);
            $table->string('transaction_status', 24);
            $table->string('network', 16)->nullable();
            $table->unsignedInteger('chain_id')->nullable();
            $table->string('token_contract', 42)->nullable();
            $table->string('destination_address', 42)->nullable();
            $table->unsignedBigInteger('amount_base_units')->nullable();
            $table->unsignedTinyInteger('token_decimals')->nullable();
            $table->unsignedInteger('confirmations')->nullable();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->dateTime('transaction_at', 6)->nullable();
            $table->char('evidence_hash', 64);
            $table->dateTime('observed_at', 6);
            $table->dateTime('created_at', 6);
            $table->unique(['provider_code', 'provider_event_id'], 'usdt_chain_event_provider_event_uniq');
            $table->unique(['provider_code', 'evidence_hash'], 'usdt_chain_event_provider_hash_uniq');
            $table->index(['txid', 'observed_at'], 'usdt_chain_event_txid_idx');
        });
        DB::statement("ALTER TABLE usdt_chain_verification_events ADD CONSTRAINT usdt_chain_event_txid_chk CHECK (`txid` REGEXP '^0x[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE usdt_chain_verification_events ADD CONSTRAINT usdt_chain_event_outcome_chk CHECK (`outcome` IN ('success','pending','rejected','uncertain','unavailable'))");
        DB::statement("ALTER TABLE usdt_chain_verification_events ADD CONSTRAINT usdt_chain_event_status_chk CHECK (`transaction_status` IN ('success','pending','failed','reverted','not_found','unknown'))");
        DB::statement('ALTER TABLE usdt_chain_verification_events ADD CONSTRAINT usdt_chain_event_hash_chk CHECK (CHAR_LENGTH(`evidence_hash`) = 64)');

        Schema::create('usdt_verified_transfers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('usdt_payment_authority_id')->unique()->constrained('usdt_payment_authorities')->restrictOnDelete();
            $table->foreignId('usdt_txid_submission_id')->unique()->constrained('usdt_txid_submissions')->restrictOnDelete();
            $table->foreignId('provider_event_row_id')->unique()->constrained('usdt_chain_verification_events')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->char('txid', 66)->unique();
            $table->unsignedBigInteger('amount_base_units');
            $table->unsignedInteger('confirmations');
            $table->char('evidence_hash', 64)->unique();
            $table->dateTime('transaction_at', 6);
            $table->dateTime('verified_at', 6);
            $table->foreignId('purchase_settlement_id')->nullable()->unique()->constrained('purchase_settlements')->restrictOnDelete();
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE usdt_verified_transfers ADD CONSTRAINT usdt_verified_transfer_txid_chk CHECK (`txid` REGEXP '^0x[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE usdt_verified_transfers ADD CONSTRAINT usdt_verified_transfer_value_chk CHECK (`amount_base_units` > 0 AND `confirmations` > 0 AND CHAR_LENGTH(`evidence_hash`) = 64)');

        Schema::create('usdt_manual_reviews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('usdt_txid_submission_id')->unique()->constrained('usdt_txid_submissions')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->string('state', 16)->default('pending');
            $table->unsignedBigInteger('decided_by_administrator_id')->nullable();
            $table->foreign('decided_by_administrator_id', 'usdt_review_admin_fk')->references('id')->on('administrators')->restrictOnDelete();
            $table->string('decision_reason', 191)->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
        DB::statement("ALTER TABLE usdt_manual_reviews ADD CONSTRAINT usdt_review_state_chk CHECK (`state` IN ('pending','approved','rejected'))");

        Schema::create('usdt_reconciliation_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('usdt_txid_submission_id')->constrained('usdt_txid_submissions')->restrictOnDelete();
            $table->char('finding_key', 64)->unique();
            $table->string('finding_type', 64);
            $table->string('severity', 16);
            $table->string('provider_code', 64)->nullable();
            $table->char('txid', 66)->nullable();
            $table->string('provider_event_id', 191)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->char('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['severity', 'created_at'], 'usdt_recon_severity_idx');
        });
        DB::statement("ALTER TABLE usdt_reconciliation_findings ADD CONSTRAINT usdt_recon_severity_chk CHECK (`severity` IN ('info','warning','high','critical'))");
        DB::statement('ALTER TABLE usdt_reconciliation_findings ADD CONSTRAINT usdt_recon_hash_chk CHECK (CHAR_LENGTH(`finding_key`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64 AND (`evidence_hash` IS NULL OR CHAR_LENGTH(`evidence_hash`) = 64))');

        $this->createGuards();
    }

    public function down(): void
    {
        if (DB::table('usdt_txid_submissions')->exists()) {
            throw new RuntimeException('Cannot roll back USDT BEP20 payment authority after TXID submission exists.');
        }
        foreach (['usdt_reconciliation_findings','usdt_manual_reviews','usdt_verified_transfers','usdt_chain_verification_events','usdt_txid_submissions','usdt_payment_authorities'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_payment_authorities_insert_guard
BEFORE INSERT ON usdt_payment_authorities
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count
    FROM payment_intents intent_row
    INNER JOIN usdt_amount_quotes amount_quote ON amount_quote.id = NEW.usdt_amount_quote_id
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'usdt_bep20'
      AND intent_row.provider_code = 'usdt_bep20'
      AND intent_row.user_id = NEW.user_id
      AND intent_row.source_quote_id = NEW.source_quote_id
      AND intent_row.source_quote_public_id = NEW.source_quote_public_id
      AND intent_row.amount_irr = NEW.source_amount_irr
      AND intent_row.currency = 'IRR'
      AND intent_row.state = 'awaiting_user_action'
      AND intent_row.captured_at IS NULL
      AND amount_quote.user_id = NEW.user_id
      AND amount_quote.source_quote_id = NEW.source_quote_id
      AND amount_quote.source_quote_public_id = NEW.source_quote_public_id
      AND amount_quote.order_amount_irr = NEW.source_amount_irr
      AND amount_quote.destination_wallet_version_id = NEW.destination_wallet_version_id
      AND amount_quote.destination_address = NEW.destination_address
      AND amount_quote.network = NEW.network
      AND amount_quote.destination_configuration_hash = NEW.destination_configuration_hash
      AND amount_quote.configuration_snapshot_hash = NEW.amount_quote_configuration_hash
      AND amount_quote.expires_at = NEW.quote_expires_at
      AND amount_quote.expires_at > NEW.created_at
      AND CAST(amount_quote.exact_usdt * 1000000 AS UNSIGNED) = NEW.expected_amount_base_units;
    IF valid_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT payment authority requires one current exact purchase/amount-quote binding.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER usdt_payment_authorities_update_guard BEFORE UPDATE ON usdt_payment_authorities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT payment authorities are immutable.'");
        DB::unprepared("CREATE TRIGGER usdt_payment_authorities_delete_guard BEFORE DELETE ON usdt_payment_authorities FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT payment authorities are non-deletable.'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_txid_submissions_insert_guard
BEFORE INSERT ON usdt_txid_submissions
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count
    FROM usdt_payment_authorities authority_row
    INNER JOIN payment_intents intent_row ON intent_row.id = authority_row.payment_intent_id
    WHERE authority_row.id = NEW.usdt_payment_authority_id
      AND authority_row.payment_intent_id = NEW.payment_intent_id
      AND authority_row.user_id = NEW.user_id
      AND intent_row.state = 'awaiting_user_action'
      AND intent_row.captured_at IS NULL;
    IF valid_count <> 1 OR NEW.state <> 'submitted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT TXID submission requires one awaiting prepared payment authority.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_txid_submissions_update_guard
BEFORE UPDATE ON usdt_txid_submissions
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id) OR NOT (NEW.submission_key <=> OLD.submission_key)
       OR NOT (NEW.usdt_payment_authority_id <=> OLD.usdt_payment_authority_id) OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.txid <=> OLD.txid)
       OR NOT (NEW.private_evidence_reference <=> OLD.private_evidence_reference) OR NOT (NEW.evidence_content_hash <=> OLD.evidence_content_hash)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash) OR NOT (NEW.submitted_at <=> OLD.submitted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT TXID submission identity is immutable.';
    END IF;
    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('verified','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'provider_unavailable' AND NEW.state IN ('verifying','pending_manual_review','rejected')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','verified','rejected')) OR
        (OLD.state = 'verified' AND NEW.state = 'captured')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT TXID submission state transition is invalid.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER usdt_txid_submissions_delete_guard BEFORE DELETE ON usdt_txid_submissions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT TXID submissions are non-deletable.'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_chain_events_insert_guard
BEFORE INSERT ON usdt_chain_verification_events
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count FROM usdt_txid_submissions submission_row
    WHERE submission_row.id = NEW.usdt_txid_submission_id AND submission_row.txid = NEW.txid;
    IF valid_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT chain event must bind the submitted TXID.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER usdt_chain_events_update_guard BEFORE UPDATE ON usdt_chain_verification_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT chain verification events are immutable.'");
        DB::unprepared("CREATE TRIGGER usdt_chain_events_delete_guard BEFORE DELETE ON usdt_chain_verification_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT chain verification events are non-deletable.'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_verified_transfers_insert_guard
BEFORE INSERT ON usdt_verified_transfers
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    DECLARE approved_review_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count
    FROM usdt_payment_authorities authority_row
    INNER JOIN usdt_txid_submissions submission_row ON submission_row.id = NEW.usdt_txid_submission_id
    INNER JOIN usdt_chain_verification_events event_row ON event_row.id = NEW.provider_event_row_id
    WHERE authority_row.id = NEW.usdt_payment_authority_id
      AND authority_row.payment_intent_id = NEW.payment_intent_id
      AND submission_row.usdt_payment_authority_id = authority_row.id
      AND submission_row.payment_intent_id = NEW.payment_intent_id
      AND submission_row.txid = NEW.txid
      AND submission_row.state IN ('verifying','pending_manual_review')
      AND event_row.usdt_txid_submission_id = submission_row.id
      AND event_row.provider_code = NEW.provider_code
      AND event_row.txid = NEW.txid
      AND event_row.outcome = 'success'
      AND event_row.transaction_status = 'success'
      AND event_row.network = authority_row.network
      AND event_row.chain_id = authority_row.chain_id
      AND event_row.token_contract = authority_row.token_contract
      AND event_row.destination_address = authority_row.destination_address
      AND event_row.amount_base_units = authority_row.expected_amount_base_units
      AND event_row.token_decimals = 6
      AND event_row.confirmations >= authority_row.minimum_confirmations
      AND event_row.evidence_hash = NEW.evidence_hash
      AND NEW.amount_base_units = authority_row.expected_amount_base_units
      AND NEW.confirmations = event_row.confirmations
      AND NEW.transaction_at = event_row.transaction_at;
    IF valid_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT verified transfer requires one exact authoritative chain observation.';
    END IF;
    IF (SELECT state FROM usdt_txid_submissions WHERE id = NEW.usdt_txid_submission_id) = 'verifying' THEN
        IF NEW.transaction_at < (SELECT created_at FROM usdt_payment_authorities WHERE id = NEW.usdt_payment_authority_id)
           OR NEW.transaction_at > (SELECT quote_expires_at FROM usdt_payment_authorities WHERE id = NEW.usdt_payment_authority_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT automatic verification cannot accept a transaction outside the locked quote window.';
        END IF;
    ELSE
        SELECT COUNT(*) INTO approved_review_count FROM usdt_manual_reviews review_row
        WHERE review_row.usdt_txid_submission_id = NEW.usdt_txid_submission_id
          AND review_row.state = 'approved' AND review_row.decided_by_administrator_id IS NOT NULL
          AND review_row.decision_reason IS NOT NULL AND review_row.decided_at IS NOT NULL;
        IF approved_review_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual verified transfer requires an approved review.';
        END IF;
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER usdt_verified_transfers_delete_guard BEFORE DELETE ON usdt_verified_transfers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT verified transfers are non-deletable.'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_verified_transfers_update_guard
BEFORE UPDATE ON usdt_verified_transfers
FOR EACH ROW
BEGIN
    DECLARE valid_settlement_count INT DEFAULT 0;
    IF NOT (NEW.public_id <=> OLD.public_id) OR NOT (NEW.usdt_payment_authority_id <=> OLD.usdt_payment_authority_id)
       OR NOT (NEW.usdt_txid_submission_id <=> OLD.usdt_txid_submission_id) OR NOT (NEW.provider_event_row_id <=> OLD.provider_event_row_id)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id) OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.txid <=> OLD.txid) OR NOT (NEW.amount_base_units <=> OLD.amount_base_units)
       OR NOT (NEW.confirmations <=> OLD.confirmations) OR NOT (NEW.evidence_hash <=> OLD.evidence_hash)
       OR NOT (NEW.transaction_at <=> OLD.transaction_at) OR NOT (NEW.verified_at <=> OLD.verified_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT verified transfer identity is immutable.';
    END IF;
    IF OLD.purchase_settlement_id IS NULL AND NEW.purchase_settlement_id IS NOT NULL THEN
        SELECT COUNT(*) INTO valid_settlement_count FROM purchase_settlements settlement_row
        WHERE settlement_row.id = NEW.purchase_settlement_id
          AND settlement_row.payment_intent_id = OLD.payment_intent_id
          AND settlement_row.provider_code = 'usdt_bep20'
          AND settlement_row.provider_transaction_id = SHA2(CONCAT('BEP20', CHAR(0), OLD.txid), 256)
          AND settlement_row.evidence_payload_hash = OLD.evidence_hash;
        IF valid_settlement_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT verified transfer settlement link is invalid.';
        END IF;
    ELSEIF NOT (NEW.purchase_settlement_id <=> OLD.purchase_settlement_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT verified transfer settlement link is immutable after first assignment.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_manual_reviews_update_guard
BEFORE UPDATE ON usdt_manual_reviews
FOR EACH ROW
BEGIN
    DECLARE active_admin_count INT DEFAULT 0;
    IF NOT (NEW.public_id <=> OLD.public_id) OR NOT (NEW.usdt_txid_submission_id <=> OLD.usdt_txid_submission_id)
       OR NOT (NEW.reason_code <=> OLD.reason_code) OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual review identity is immutable.';
    END IF;
    IF OLD.state = 'pending' AND NEW.state IN ('approved','rejected') THEN
        IF NEW.decided_by_administrator_id IS NULL OR NEW.decision_reason IS NULL OR CHAR_LENGTH(TRIM(NEW.decision_reason)) < 1 OR NEW.decided_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual review decision requires administrator, reason and timestamp.';
        END IF;
        SELECT COUNT(*) INTO active_admin_count FROM administrators admin_row
        WHERE admin_row.id = NEW.decided_by_administrator_id AND admin_row.status = 'active';
        IF active_admin_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual review decision requires an active administrator.';
        END IF;
    ELSEIF NOT (NEW.state <=> OLD.state) OR NOT (NEW.decided_by_administrator_id <=> OLD.decided_by_administrator_id)
       OR NOT (NEW.decision_reason <=> OLD.decision_reason) OR NOT (NEW.decided_at <=> OLD.decided_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual review decision transition is invalid.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER usdt_manual_reviews_delete_guard BEFORE DELETE ON usdt_manual_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT manual reviews are non-deletable.'");
        DB::unprepared("CREATE TRIGGER usdt_reconciliation_findings_update_guard BEFORE UPDATE ON usdt_reconciliation_findings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT reconciliation findings are immutable.'");
        DB::unprepared("CREATE TRIGGER usdt_reconciliation_findings_delete_guard BEFORE DELETE ON usdt_reconciliation_findings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT reconciliation findings are non-deletable.'");
    }
};