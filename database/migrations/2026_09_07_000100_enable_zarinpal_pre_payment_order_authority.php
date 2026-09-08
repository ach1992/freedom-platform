<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement IPG-001 PAY-002 PAY-003 PRO-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('zarinpal_provider_evidence_claims', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('zarinpal_payment_request_id')->unique('zpec_request_unique');
            $table->foreign('zarinpal_payment_request_id', 'zpec_request_fk')
                ->references('id')
                ->on('zarinpal_payment_requests')
                ->restrictOnDelete();
            $table->string('authority', 64)->unique('zpec_authority_unique');
            $table->string('provider_ref_id', 64)->unique('zpec_provider_ref_unique');
            $table->char('evidence_payload_hash', 64);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE zarinpal_provider_evidence_claims ADD CONSTRAINT zpec_money_chk CHECK (`amount_irr` > 0 AND `currency` = 'IRR')");
        DB::statement('ALTER TABLE zarinpal_provider_evidence_claims ADD CONSTRAINT zpec_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');
        DB::statement(<<<'SQL'
INSERT INTO zarinpal_provider_evidence_claims (
    zarinpal_payment_request_id,
    authority,
    provider_ref_id,
    evidence_payload_hash,
    amount_irr,
    currency,
    created_at
)
SELECT
    verification_row.zarinpal_payment_request_id,
    verification_row.authority,
    verification_row.provider_ref_id,
    verification_row.evidence_payload_hash,
    verification_row.amount_irr,
    verification_row.currency,
    verification_row.created_at
FROM zarinpal_payment_verifications verification_row
SQL);

        Schema::create('zarinpal_verified_unsettled_evidence', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('zarinpal_payment_request_id');
            $table->unique('zarinpal_payment_request_id', 'zvue_request_unique');
            $table->foreign('zarinpal_payment_request_id', 'zvue_request_fk')
                ->references('id')
                ->on('zarinpal_payment_requests')
                ->restrictOnDelete();
            $table->string('authority', 64)->unique();
            $table->string('provider_ref_id', 64)->unique();
            $table->integer('provider_verify_code');
            $table->char('evidence_payload_hash', 64);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3);
            $table->dateTime('verified_at', 6);
            $table->dateTime('provider_reverse_eligible_until', 6);
            $table->string('reason_code', 64);
            $table->dateTime('created_at', 6);
        });
        DB::statement('ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_verify_code_chk CHECK (`provider_verify_code` IN (100,101))');
        DB::statement("ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_money_chk CHECK (`amount_irr` > 0 AND `currency` = 'IRR')");
        DB::statement('ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');
        DB::statement('ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_reverse_window_chk CHECK (`provider_reverse_eligible_until` >= `verified_at`)');
        DB::statement("ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_reason_chk CHECK (`reason_code` IN ('purchase_order_unavailable','provider_result_conflict'))");

        Schema::create('zarinpal_reconciliation_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('zarinpal_payment_request_id');
            $table->foreign('zarinpal_payment_request_id', 'zrf_request_fk')
                ->references('id')
                ->on('zarinpal_payment_requests')
                ->restrictOnDelete();
            $table->char('finding_key', 64)->unique();
            $table->string('finding_type', 64);
            $table->string('severity', 16);
            $table->string('observed_result', 16);
            $table->string('provider_ref_id', 64)->nullable();
            $table->integer('provider_code')->nullable();
            $table->char('evidence_hash', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['zarinpal_payment_request_id', 'created_at'], 'zrf_request_created_idx');
            $table->index(['finding_type', 'severity', 'created_at'], 'zrf_type_severity_created_idx');
        });
        DB::statement("ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_type_chk CHECK (`finding_type` IN ('verified_purchase_order_unavailable','provider_verification_conflict'))");
        DB::statement("ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_severity_chk CHECK (`severity` = 'critical')");
        DB::statement("ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_result_chk CHECK (`observed_result` IN ('verified','rejected','uncertain'))");
        DB::statement('ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_hash_chk CHECK (CHAR_LENGTH(`finding_key`) = 64 AND CHAR_LENGTH(`evidence_hash`) = 64)');
        DB::statement("ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_observed_shape_chk CHECK ((`observed_result` = 'verified' AND `provider_ref_id` IS NOT NULL AND `provider_code` IN (100,101)) OR (`observed_result` = 'rejected' AND `provider_ref_id` IS NULL AND (`provider_code` IS NULL OR `provider_code` NOT IN (100,101))) OR (`observed_result` = 'uncertain' AND `provider_ref_id` IS NULL AND `provider_code` IS NULL))");

        DB::statement('ALTER TABLE zarinpal_payment_observations DROP CONSTRAINT zarinpal_observation_type_chk');
        DB::statement("ALTER TABLE zarinpal_payment_observations ADD CONSTRAINT zarinpal_observation_type_chk CHECK (`event_type` IN ('request_accepted','request_rejected','request_uncertain','callback_ok','callback_nok','verify_started','verify_rejected','verify_uncertain','inquiry_verified','inquiry_paid','inquiry_in_bank','inquiry_failed','inquiry_reversed','inquiry_unavailable','unverified_discovery','manual_review'))");

        $this->createProviderEvidenceClaimGuards();
        $this->createEvidenceGuards();
        $this->createVerificationConflictGuard();
        $this->createFindingGuards();
    }

    public function down(): void
    {
        $hasPrePaymentAuthority = DB::table('zarinpal_payment_requests as request_row')
            ->join('payment_intents as intent_row', 'intent_row.id', '=', 'request_row.payment_intent_id')
            ->join('orders as order_row', function ($join): void {
                $join->on('order_row.source_quote_id', '=', 'intent_row.source_quote_id')
                    ->on('order_row.source_quote_public_id', '=', 'intent_row.source_quote_public_id')
                    ->on('order_row.user_id', '=', 'intent_row.user_id');
            })
            ->where('intent_row.purpose', 'purchase')
            ->where('intent_row.provider_code', 'zarinpal')
            ->where('order_row.source_type', 'purchase')
            ->exists();

        if ($hasPrePaymentAuthority
            || DB::table('zarinpal_reconciliation_findings')->exists()
            || DB::table('zarinpal_verified_unsettled_evidence')->exists()) {
            throw new RuntimeException('Cannot roll back Zarinpal pre-payment Order authority while durable pre-payment or reconciliation authority exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_verifications_unsettled_conflict_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_reconciliation_findings_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_reconciliation_findings_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_reconciliation_findings_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_verified_unsettled_evidence_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_verified_unsettled_evidence_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_verified_unsettled_evidence_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_provider_evidence_claims_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_provider_evidence_claims_update_guard');

        Schema::dropIfExists('zarinpal_reconciliation_findings');
        Schema::dropIfExists('zarinpal_verified_unsettled_evidence');
        Schema::dropIfExists('zarinpal_provider_evidence_claims');

        DB::statement('ALTER TABLE zarinpal_payment_observations DROP CONSTRAINT zarinpal_observation_type_chk');
        DB::statement("ALTER TABLE zarinpal_payment_observations ADD CONSTRAINT zarinpal_observation_type_chk CHECK (`event_type` IN ('request_accepted','request_rejected','request_uncertain','callback_ok','callback_nok','verify_rejected','verify_uncertain','inquiry_verified','inquiry_paid','inquiry_in_bank','inquiry_failed','inquiry_reversed','inquiry_unavailable','unverified_discovery','manual_review'))");
    }

    private function createProviderEvidenceClaimGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_provider_evidence_claims_update_guard
BEFORE UPDATE ON zarinpal_provider_evidence_claims
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal provider evidence claims are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_provider_evidence_claims_delete_guard
BEFORE DELETE ON zarinpal_provider_evidence_claims
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal provider evidence claims are non-deletable.';
END
SQL);
    }

    private function createEvidenceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_verified_unsettled_evidence_insert_guard
BEFORE INSERT ON zarinpal_verified_unsettled_evidence
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;
    DECLARE matching_claim_count INT DEFAULT 0;

    IF NEW.reason_code = 'purchase_order_unavailable' THEN
        SELECT COUNT(*) INTO valid_authority_count
        FROM zarinpal_payment_requests request_row
        INNER JOIN payment_intents intent_row ON intent_row.id = request_row.payment_intent_id
        INNER JOIN orders order_row
            ON order_row.source_type = 'purchase'
           AND order_row.source_quote_id = intent_row.source_quote_id
           AND order_row.source_quote_public_id = intent_row.source_quote_public_id
           AND order_row.user_id = intent_row.user_id
        INNER JOIN purchase_settlements winning_settlement
            ON winning_settlement.id = order_row.purchase_settlement_id
           AND winning_settlement.public_id = order_row.purchase_settlement_public_id
           AND winning_settlement.payment_intent_id = order_row.payment_intent_id
           AND winning_settlement.user_id = order_row.user_id
           AND winning_settlement.source_quote_id = order_row.source_quote_id
           AND winning_settlement.source_quote_public_id = order_row.source_quote_public_id
           AND winning_settlement.amount_irr = order_row.settled_amount_irr
           AND winning_settlement.currency = order_row.currency
           AND winning_settlement.settled_at = order_row.paid_at
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
          AND intent_row.state IN ('submitted','verifying','pending_manual_review')
          AND intent_row.captured_at IS NULL
          AND order_row.state IN ('paid','provisioning_queued','provisioning','completed','needs_review','refund_pending','refunded','partially_refunded')
          AND order_row.state_version >= 1
          AND order_row.payment_intent_id IS NOT NULL
          AND order_row.payment_intent_id <> intent_row.id
          AND order_row.total_amount_irr = NEW.amount_irr
          AND order_row.settled_amount_irr = NEW.amount_irr
          AND order_row.currency = NEW.currency
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = intent_row.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM zarinpal_payment_verifications verification_row
              WHERE verification_row.zarinpal_payment_request_id = request_row.id
          );
    ELSEIF NEW.reason_code = 'provider_result_conflict' THEN
        SELECT COUNT(*) INTO valid_authority_count
        FROM zarinpal_payment_requests request_row
        INNER JOIN payment_intents intent_row ON intent_row.id = request_row.payment_intent_id
        WHERE request_row.id = NEW.zarinpal_payment_request_id
          AND request_row.state = 'failed'
          AND request_row.authority = NEW.authority
          AND request_row.amount_irr = NEW.amount_irr
          AND request_row.currency = NEW.currency
          AND request_row.merchant_configuration_hash IS NOT NULL
          AND intent_row.purpose = 'purchase'
          AND intent_row.provider_code = 'zarinpal'
          AND intent_row.amount_irr = NEW.amount_irr
          AND intent_row.currency = NEW.currency
          AND intent_row.state IN ('failed','awaiting_user_action')
          AND intent_row.captured_at IS NULL
          AND EXISTS (
              SELECT 1
              FROM zarinpal_payment_observations observation_row
              WHERE observation_row.zarinpal_payment_request_id = request_row.id
                AND observation_row.event_type = 'verify_rejected'
          )
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = intent_row.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM zarinpal_payment_verifications verification_row
              WHERE verification_row.zarinpal_payment_request_id = request_row.id
          );
    END IF;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsettled Zarinpal verification requires one unique matching uncaptured provider authority and valid reason state.';
    END IF;

    INSERT IGNORE INTO zarinpal_provider_evidence_claims (
        zarinpal_payment_request_id,
        authority,
        provider_ref_id,
        evidence_payload_hash,
        amount_irr,
        currency,
        created_at
    ) VALUES (
        NEW.zarinpal_payment_request_id,
        NEW.authority,
        NEW.provider_ref_id,
        NEW.evidence_payload_hash,
        NEW.amount_irr,
        NEW.currency,
        NEW.created_at
    );

    SELECT COUNT(*) INTO matching_claim_count
    FROM zarinpal_provider_evidence_claims claim_row
    WHERE claim_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
      AND claim_row.authority = NEW.authority
      AND claim_row.provider_ref_id = NEW.provider_ref_id
      AND claim_row.evidence_payload_hash = NEW.evidence_payload_hash
      AND claim_row.amount_irr = NEW.amount_irr
      AND claim_row.currency = NEW.currency;

    IF matching_claim_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsettled Zarinpal verification conflicts with the shared provider evidence identity authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_verified_unsettled_evidence_update_guard
BEFORE UPDATE ON zarinpal_verified_unsettled_evidence
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsettled Zarinpal verification evidence is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_verified_unsettled_evidence_delete_guard
BEFORE DELETE ON zarinpal_verified_unsettled_evidence
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsettled Zarinpal verification evidence is non-deletable.';
END
SQL);
    }

    private function createVerificationConflictGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_verifications_unsettled_conflict_guard
BEFORE INSERT ON zarinpal_payment_verifications
FOR EACH ROW
BEGIN
    DECLARE matching_claim_count INT DEFAULT 0;

    INSERT IGNORE INTO zarinpal_provider_evidence_claims (
        zarinpal_payment_request_id,
        authority,
        provider_ref_id,
        evidence_payload_hash,
        amount_irr,
        currency,
        created_at
    ) VALUES (
        NEW.zarinpal_payment_request_id,
        NEW.authority,
        NEW.provider_ref_id,
        NEW.evidence_payload_hash,
        NEW.amount_irr,
        NEW.currency,
        NEW.created_at
    );

    SELECT COUNT(*) INTO matching_claim_count
    FROM zarinpal_provider_evidence_claims claim_row
    WHERE claim_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
      AND claim_row.authority = NEW.authority
      AND claim_row.provider_ref_id = NEW.provider_ref_id
      AND claim_row.evidence_payload_hash = NEW.evidence_payload_hash
      AND claim_row.amount_irr = NEW.amount_irr
      AND claim_row.currency = NEW.currency;

    IF matching_claim_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settled Zarinpal verification conflicts with the shared provider evidence identity authority.';
    END IF;
END
SQL);
    }

    private function createFindingGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_reconciliation_findings_insert_guard
BEFORE INSERT ON zarinpal_reconciliation_findings
FOR EACH ROW
BEGIN
    DECLARE matching_evidence_count INT DEFAULT 0;
    DECLARE accepted_evidence_count INT DEFAULT 0;
    DECLARE compatible_evidence_count INT DEFAULT 0;
    DECLARE conflicting_claim_count INT DEFAULT 0;
    DECLARE matching_observation_count INT DEFAULT 0;

    IF NEW.finding_type = 'verified_purchase_order_unavailable' THEN
        SELECT COUNT(*) INTO matching_evidence_count
        FROM zarinpal_verified_unsettled_evidence evidence_row
        WHERE evidence_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
          AND evidence_row.provider_ref_id = NEW.provider_ref_id
          AND evidence_row.provider_verify_code = NEW.provider_code
          AND evidence_row.evidence_payload_hash = NEW.evidence_hash
          AND evidence_row.reason_code = 'purchase_order_unavailable';

        IF NEW.observed_result <> 'verified' OR matching_evidence_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal purchase-Order reconciliation finding requires matching immutable unsettled evidence.';
        END IF;
    ELSEIF NEW.finding_type = 'provider_verification_conflict' THEN
        SELECT COUNT(*) INTO accepted_evidence_count
        FROM (
            SELECT verification_row.id
            FROM zarinpal_payment_verifications verification_row
            WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
            UNION ALL
            SELECT evidence_row.id
            FROM zarinpal_verified_unsettled_evidence evidence_row
            WHERE evidence_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
        ) accepted_rows;

        IF NEW.observed_result = 'verified' THEN
            SELECT COUNT(*) INTO compatible_evidence_count
            FROM (
                SELECT verification_row.provider_ref_id, verification_row.evidence_payload_hash
                FROM zarinpal_payment_verifications verification_row
                WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
                UNION ALL
                SELECT evidence_row.provider_ref_id, evidence_row.evidence_payload_hash
                FROM zarinpal_verified_unsettled_evidence evidence_row
                WHERE evidence_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
            ) accepted_rows
            WHERE accepted_rows.provider_ref_id = NEW.provider_ref_id
              AND accepted_rows.evidence_payload_hash = NEW.evidence_hash;

            SELECT COUNT(*) INTO conflicting_claim_count
            FROM zarinpal_provider_evidence_claims claim_row
            INNER JOIN zarinpal_payment_requests request_row
                ON request_row.id = NEW.zarinpal_payment_request_id
            WHERE (
                claim_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
                OR claim_row.authority = request_row.authority
                OR claim_row.provider_ref_id = NEW.provider_ref_id
            )
              AND NOT (
                  claim_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
                  AND claim_row.authority = request_row.authority
                  AND claim_row.provider_ref_id = NEW.provider_ref_id
                  AND claim_row.evidence_payload_hash = NEW.evidence_hash
              );

            IF NOT ((accepted_evidence_count = 1 AND compatible_evidence_count = 0) OR conflicting_claim_count > 0) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verified Zarinpal conflict requires incompatible accepted evidence or a conflicting shared provider identity claim.';
            END IF;
        ELSEIF NEW.observed_result = 'rejected' THEN
            IF accepted_evidence_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rejected Zarinpal verification conflict requires exactly one accepted provider evidence authority.';
            END IF;

            SELECT COUNT(*) INTO matching_observation_count
            FROM zarinpal_payment_observations observation_row
            WHERE observation_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
              AND observation_row.event_type = 'verify_rejected'
              AND observation_row.correlation_id = NEW.correlation_id
              AND (observation_row.provider_code <=> NEW.provider_code);

            IF matching_observation_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rejected Zarinpal verification conflict requires its immutable provider observation.';
            END IF;
        ELSEIF NEW.observed_result = 'uncertain' THEN
            IF accepted_evidence_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Uncertain Zarinpal verification conflict requires exactly one accepted provider evidence authority.';
            END IF;

            SELECT COUNT(*) INTO matching_observation_count
            FROM zarinpal_payment_observations observation_row
            WHERE observation_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
              AND observation_row.event_type = 'verify_uncertain'
              AND observation_row.correlation_id = NEW.correlation_id;

            IF matching_observation_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Uncertain Zarinpal verification conflict requires its immutable provider observation.';
            END IF;
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_reconciliation_findings_update_guard
BEFORE UPDATE ON zarinpal_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal reconciliation findings are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_reconciliation_findings_delete_guard
BEFORE DELETE ON zarinpal_reconciliation_findings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal reconciliation findings are non-deletable.';
END
SQL);
    }
};
