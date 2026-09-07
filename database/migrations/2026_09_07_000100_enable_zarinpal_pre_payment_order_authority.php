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
        Schema::create('zarinpal_verified_unsettled_evidence', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('zarinpal_payment_request_id')->unique();
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
        DB::statement("ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_verify_code_chk CHECK (`provider_verify_code` IN (100,101))");
        DB::statement("ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_money_chk CHECK (`amount_irr` > 0 AND `currency` = 'IRR')");
        DB::statement('ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');
        DB::statement('ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_reverse_window_chk CHECK (`provider_reverse_eligible_until` >= `verified_at`)');
        DB::statement("ALTER TABLE zarinpal_verified_unsettled_evidence ADD CONSTRAINT zvue_reason_chk CHECK (`reason_code` = 'purchase_order_unavailable')");

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
            $table->string('provider_ref_id', 64)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['zarinpal_payment_request_id', 'created_at'], 'zrf_request_created_idx');
            $table->index(['finding_type', 'severity', 'created_at'], 'zrf_type_severity_created_idx');
        });
        DB::statement("ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_type_chk CHECK (`finding_type` = 'verified_purchase_order_unavailable')");
        DB::statement("ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_severity_chk CHECK (`severity` = 'critical')");
        DB::statement('ALTER TABLE zarinpal_reconciliation_findings ADD CONSTRAINT zrf_hash_chk CHECK (CHAR_LENGTH(`finding_key`) = 64 AND (`evidence_hash` IS NULL OR CHAR_LENGTH(`evidence_hash`) = 64))');

        $this->createEvidenceGuards();
        $this->createFindingGuards();
    }

    public function down(): void
    {
        if (DB::table('zarinpal_reconciliation_findings')->exists()
            || DB::table('zarinpal_verified_unsettled_evidence')->exists()) {
            throw new RuntimeException('Cannot roll back Zarinpal pre-payment Order authority while reconciliation evidence exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_reconciliation_findings_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_reconciliation_findings_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_reconciliation_findings_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_verified_unsettled_evidence_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_verified_unsettled_evidence_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_verified_unsettled_evidence_insert_guard');
        Schema::dropIfExists('zarinpal_reconciliation_findings');
        Schema::dropIfExists('zarinpal_verified_unsettled_evidence');
    }

    private function createEvidenceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_verified_unsettled_evidence_insert_guard
BEFORE INSERT ON zarinpal_verified_unsettled_evidence
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_authority_count
    FROM zarinpal_payment_requests request_row
    INNER JOIN payment_intents intent_row ON intent_row.id = request_row.payment_intent_id
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
      AND NOT EXISTS (
          SELECT 1
          FROM purchase_settlements settlement_row
          WHERE settlement_row.payment_intent_id = intent_row.id
      );

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsettled Zarinpal verification requires one matching uncaptured provider authority.';
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

    private function createFindingGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_reconciliation_findings_insert_guard
BEFORE INSERT ON zarinpal_reconciliation_findings
FOR EACH ROW
BEGIN
    DECLARE matching_evidence_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_evidence_count
    FROM zarinpal_verified_unsettled_evidence evidence_row
    WHERE evidence_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
      AND evidence_row.provider_ref_id = NEW.provider_ref_id
      AND evidence_row.evidence_payload_hash = NEW.evidence_hash
      AND evidence_row.reason_code = 'purchase_order_unavailable';

    IF matching_evidence_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal reconciliation finding requires matching immutable unsettled evidence.';
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
