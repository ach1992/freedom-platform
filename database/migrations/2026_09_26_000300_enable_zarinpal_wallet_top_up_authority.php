<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement IPG-001 PAY-002 PAY-003 WAL-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('zarinpal_wallet_top_up_verifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('zarinpal_payment_request_id')->unique('zwuv_request_unique');
            $table->foreign('zarinpal_payment_request_id', 'zwuv_request_fk')
                ->references('id')->on('zarinpal_payment_requests')->restrictOnDelete();
            $table->foreignId('wallet_top_up_settlement_id')->unique('zwuv_settlement_unique');
            $table->foreign('wallet_top_up_settlement_id', 'zwuv_settlement_fk')
                ->references('id')->on('wallet_top_up_settlements')->restrictOnDelete();
            $table->string('authority', 64)->collation('utf8mb4_bin')->unique('zwuv_authority_unique');
            $table->string('provider_ref_id', 64)->collation('utf8mb4_bin')->unique('zwuv_provider_ref_unique');
            $table->integer('provider_verify_code');
            $table->char('evidence_payload_hash', 64)->collation('utf8mb4_bin');
            $table->bigInteger('amount_irr');
            $table->char('currency', 3)->collation('utf8mb4_bin');
            $table->dateTime('verified_at', 6);
            $table->dateTime('provider_reverse_eligible_until', 6);
            $table->dateTime('created_at', 6);
        });
        DB::statement('ALTER TABLE zarinpal_wallet_top_up_verifications ADD CONSTRAINT zwuv_code_chk CHECK (`provider_verify_code` IN (100,101))');
        DB::statement("ALTER TABLE zarinpal_wallet_top_up_verifications ADD CONSTRAINT zwuv_money_chk CHECK (`amount_irr` > 0 AND BINARY `currency` = BINARY 'IRR')");
        DB::statement('ALTER TABLE zarinpal_wallet_top_up_verifications ADD CONSTRAINT zwuv_hash_chk CHECK (CHAR_LENGTH(`evidence_payload_hash`) = 64)');
        DB::statement('ALTER TABLE zarinpal_wallet_top_up_verifications ADD CONSTRAINT zwuv_reverse_window_chk CHECK (`provider_reverse_eligible_until` >= `verified_at`)');

        $this->replaceRequestGuards(true);
        $this->replaceProviderEvidenceClaimGuard(true);
        $this->replaceUnsettledEvidenceGuard(true);
        $this->createWalletTopUpVerificationGuards();
        $this->replaceFindingGuard(true);
    }

    public function down(): void
    {
        if (DB::table('zarinpal_wallet_top_up_verifications')->exists()) {
            throw new RuntimeException('Cannot roll back Zarinpal wallet top-up authority after verified financial state exists.');
        }

        $this->replaceRequestGuards(false);
        $this->replaceProviderEvidenceClaimGuard(false);
        $this->replaceUnsettledEvidenceGuard(false);
        $this->replaceFindingGuard(false);

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_wallet_top_up_verifications_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_wallet_top_up_verifications_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_wallet_top_up_verifications_insert_guard');
        Schema::dropIfExists('zarinpal_wallet_top_up_verifications');
    }

    private function replaceRequestGuards(bool $allowWalletTopUp): void
    {
        $purposePredicate = $allowWalletTopUp
            ? <<<'SQL'
      AND intent_row.purpose IN ('purchase','wallet_top_up')
      AND (
          (intent_row.purpose = 'purchase'
              AND intent_row.source_quote_id IS NOT NULL
              AND intent_row.source_quote_public_id IS NOT NULL)
          OR
          (intent_row.purpose = 'wallet_top_up'
              AND intent_row.wallet_account_id IS NOT NULL
              AND intent_row.source_quote_id IS NULL
              AND intent_row.source_quote_public_id IS NULL)
      )
SQL
            : "      AND intent_row.purpose = 'purchase'";

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_requests_insert_guard');
        DB::unprepared(<<<SQL
CREATE TRIGGER zarinpal_payment_requests_insert_guard
BEFORE INSERT ON zarinpal_payment_requests
FOR EACH ROW
BEGIN
    DECLARE matching_intent_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
{$purposePredicate}
      AND intent_row.provider_code = 'zarinpal'
      AND intent_row.amount_irr = NEW.amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.state = 'awaiting_user_action'
      AND intent_row.captured_at IS NULL;

    IF matching_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request requires one matching awaiting payment intent.';
    END IF;
    IF NEW.state <> 'initiating' OR NEW.authority IS NOT NULL OR NEW.authority_received_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request must start in initiating state without authority.';
    END IF;
END
SQL);

        $verificationCount = $allowWalletTopUp
            ? <<<'SQL'
        SELECT
            (SELECT COUNT(*)
             FROM zarinpal_payment_verifications verification_row
             WHERE verification_row.zarinpal_payment_request_id = NEW.id
               AND BINARY verification_row.authority = BINARY NEW.authority
               AND verification_row.amount_irr = NEW.amount_irr
               AND BINARY verification_row.currency = BINARY NEW.currency)
          + (SELECT COUNT(*)
             FROM zarinpal_wallet_top_up_verifications verification_row
             WHERE verification_row.zarinpal_payment_request_id = NEW.id
               AND BINARY verification_row.authority = BINARY NEW.authority
               AND verification_row.amount_irr = NEW.amount_irr
               AND BINARY verification_row.currency = BINARY NEW.currency)
        INTO verification_count;
SQL
            : <<<'SQL'
        SELECT COUNT(*) INTO verification_count
        FROM zarinpal_payment_verifications verification_row
        WHERE verification_row.zarinpal_payment_request_id = NEW.id
          AND BINARY verification_row.authority = BINARY NEW.authority
          AND verification_row.amount_irr = NEW.amount_irr
          AND BINARY verification_row.currency = BINARY NEW.currency;
SQL;

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_requests_update_guard');
        DB::unprepared(<<<SQL
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
{$verificationCount}
        IF verification_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verified Zarinpal request requires exactly one immutable verification authority.';
        END IF;
    END IF;
END
SQL);
    }

    private function replaceProviderEvidenceClaimGuard(bool $allowWalletTopUp): void
    {
        $purpose = $allowWalletTopUp
            ? "intent_row.purpose IN ('purchase','wallet_top_up')"
            : "intent_row.purpose = 'purchase'";

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_provider_evidence_claims_insert_guard');
        DB::unprepared(<<<SQL
CREATE TRIGGER zarinpal_provider_evidence_claims_insert_guard
BEFORE INSERT ON zarinpal_provider_evidence_claims
FOR EACH ROW
BEGIN
    DECLARE matching_request_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_request_count
    FROM zarinpal_payment_requests request_row
    INNER JOIN payment_intents intent_row ON intent_row.id = request_row.payment_intent_id
    WHERE request_row.id = NEW.zarinpal_payment_request_id
      AND BINARY request_row.authority = BINARY NEW.authority
      AND request_row.amount_irr = NEW.amount_irr
      AND BINARY request_row.currency = BINARY NEW.currency
      AND request_row.merchant_configuration_hash IS NOT NULL
      AND request_row.state IN ('redirectable','manual_review','verified','failed')
      AND {$purpose}
      AND intent_row.provider_code = 'zarinpal'
      AND intent_row.amount_irr = NEW.amount_irr
      AND BINARY intent_row.currency = BINARY NEW.currency;

    IF matching_request_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal provider evidence claim requires one matching durable provider request authority.';
    END IF;
END
SQL);
    }

    private function replaceUnsettledEvidenceGuard(bool $allowWalletTopUp): void
    {
        $conflictPurpose = $allowWalletTopUp
            ? "intent_row.purpose IN ('purchase','wallet_top_up')"
            : "intent_row.purpose = 'purchase'";
        $topUpAbsence = $allowWalletTopUp
            ? <<<'SQL'
          AND NOT EXISTS (
              SELECT 1 FROM zarinpal_wallet_top_up_verifications verification_row
              WHERE verification_row.zarinpal_payment_request_id = request_row.id
          )
SQL
            : '';
        $purchaseSettlementAbsence = $allowWalletTopUp
            ? <<<'SQL'
          AND (
              intent_row.purpose = 'wallet_top_up'
              OR NOT EXISTS (
                  SELECT 1 FROM purchase_settlements settlement_row
                  WHERE settlement_row.payment_intent_id = intent_row.id
              )
          )
SQL
            : <<<'SQL'
          AND NOT EXISTS (
              SELECT 1 FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = intent_row.id
          )
SQL;

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_verified_unsettled_evidence_insert_guard');
        DB::unprepared(<<<SQL
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
          AND NOT (order_row.state = 'awaiting_payment' AND order_row.state_version = 0)
          AND order_row.total_amount_irr = NEW.amount_irr
          AND order_row.currency = NEW.currency
          AND NOT EXISTS (
              SELECT 1 FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = intent_row.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM zarinpal_payment_verifications verification_row
              WHERE verification_row.zarinpal_payment_request_id = request_row.id
          );
    ELSEIF NEW.reason_code = 'promotion_authority_unavailable' THEN
        SELECT COUNT(*) INTO valid_authority_count
        FROM zarinpal_payment_requests request_row
        INNER JOIN payment_intents intent_row ON intent_row.id = request_row.payment_intent_id
        INNER JOIN quotes quote_row ON quote_row.id = intent_row.source_quote_id
        INNER JOIN orders order_row
            ON order_row.source_type = 'purchase'
           AND order_row.source_quote_id = intent_row.source_quote_id
           AND order_row.source_quote_public_id = intent_row.source_quote_public_id
           AND order_row.user_id = intent_row.user_id
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
          AND quote_row.public_id = intent_row.source_quote_public_id
          AND quote_row.user_id = intent_row.user_id
          AND quote_row.final_price_irr = NEW.amount_irr
          AND quote_row.currency = NEW.currency
          AND quote_row.discount_irr > 0
          AND quote_row.discount_reference_code IS NOT NULL
          AND order_row.state = 'awaiting_payment'
          AND order_row.state_version = 0
          AND order_row.total_amount_irr = NEW.amount_irr
          AND order_row.currency = NEW.currency
          AND order_row.purchase_settlement_id IS NULL
          AND order_row.purchase_settlement_public_id IS NULL
          AND order_row.payment_intent_id IS NULL
          AND order_row.payment_intent_public_id IS NULL
          AND order_row.settled_amount_irr IS NULL
          AND order_row.paid_at IS NULL
          AND (
              request_row.promotion_usage_reservation_id IS NULL
              OR NOT EXISTS (
                  SELECT 1
                  FROM promotion_usage_reservations reservation_row
                  LEFT JOIN promotion_usage_releases release_row ON release_row.promotion_usage_reservation_id = reservation_row.id
                  LEFT JOIN promotion_usage_redemptions redemption_row ON redemption_row.promotion_usage_reservation_id = reservation_row.id
                  WHERE reservation_row.id = request_row.promotion_usage_reservation_id
                    AND reservation_row.quote_id = intent_row.source_quote_id
                    AND reservation_row.user_id = intent_row.user_id
                    AND release_row.id IS NULL
                    AND redemption_row.id IS NULL
              )
          )
          AND NOT EXISTS (
              SELECT 1 FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = intent_row.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM zarinpal_payment_verifications verification_row
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
          AND {$conflictPurpose}
          AND intent_row.provider_code = 'zarinpal'
          AND intent_row.amount_irr = NEW.amount_irr
          AND intent_row.currency = NEW.currency
          AND intent_row.state IN ('failed','awaiting_user_action')
          AND intent_row.captured_at IS NULL
          AND EXISTS (
              SELECT 1 FROM zarinpal_payment_observations observation_row
              WHERE observation_row.zarinpal_payment_request_id = request_row.id
                AND observation_row.event_type = 'verify_rejected'
          )
{$purchaseSettlementAbsence}
          AND NOT EXISTS (
              SELECT 1 FROM zarinpal_payment_verifications verification_row
              WHERE verification_row.zarinpal_payment_request_id = request_row.id
          )
{$topUpAbsence};
    END IF;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsettled Zarinpal verification requires one unique matching uncaptured provider authority and valid reason state.';
    END IF;

    INSERT IGNORE INTO zarinpal_provider_evidence_claims (
        zarinpal_payment_request_id, authority, provider_ref_id, evidence_payload_hash,
        evidence_disposition, amount_irr, currency, created_at
    ) VALUES (
        NEW.zarinpal_payment_request_id, NEW.authority, NEW.provider_ref_id,
        NEW.evidence_payload_hash, 'unsettled', NEW.amount_irr, NEW.currency, NEW.created_at
    );

    SELECT COUNT(*) INTO matching_claim_count
    FROM zarinpal_provider_evidence_claims claim_row
    WHERE claim_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
      AND BINARY claim_row.authority = BINARY NEW.authority
      AND BINARY claim_row.provider_ref_id = BINARY NEW.provider_ref_id
      AND BINARY claim_row.evidence_payload_hash = BINARY NEW.evidence_payload_hash
      AND BINARY claim_row.evidence_disposition = BINARY 'unsettled'
      AND claim_row.amount_irr = NEW.amount_irr
      AND BINARY claim_row.currency = BINARY NEW.currency;

    IF matching_claim_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsettled Zarinpal verification conflicts with the shared provider evidence identity authority.';
    END IF;
END
SQL);
    }

    private function createWalletTopUpVerificationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_wallet_top_up_verifications_insert_guard
BEFORE INSERT ON zarinpal_wallet_top_up_verifications
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;
    DECLARE matching_claim_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_authority_count
    FROM zarinpal_payment_requests request_row
    INNER JOIN payment_intents intent_row ON intent_row.id = request_row.payment_intent_id
    INNER JOIN wallet_top_up_settlements settlement_row ON settlement_row.payment_intent_id = intent_row.id
    INNER JOIN payment_provider_transactions provider_row ON provider_row.id = settlement_row.provider_transaction_row_id
    WHERE request_row.id = NEW.zarinpal_payment_request_id
      AND request_row.state IN ('redirectable','manual_review')
      AND BINARY request_row.authority = BINARY NEW.authority
      AND request_row.amount_irr = NEW.amount_irr
      AND BINARY request_row.currency = BINARY NEW.currency
      AND request_row.merchant_configuration_hash IS NOT NULL
      AND request_row.promotion_usage_reservation_id IS NULL
      AND intent_row.purpose = 'wallet_top_up'
      AND intent_row.provider_code = 'zarinpal'
      AND intent_row.amount_irr = NEW.amount_irr
      AND BINARY intent_row.currency = BINARY NEW.currency
      AND intent_row.state = 'captured'
      AND intent_row.captured_at IS NOT NULL
      AND settlement_row.id = NEW.wallet_top_up_settlement_id
      AND settlement_row.amount_irr = NEW.amount_irr
      AND provider_row.provider_code = 'zarinpal'
      AND BINARY provider_row.provider_transaction_id = BINARY NEW.provider_ref_id
      AND BINARY provider_row.evidence_payload_hash = BINARY NEW.evidence_payload_hash
      AND provider_row.transaction_status = 'settled'
      AND provider_row.amount_irr = NEW.amount_irr
      AND BINARY provider_row.currency = BINARY NEW.currency;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal wallet top-up verification must match request, intent, settlement and authoritative provider transaction.';
    END IF;

    SELECT COUNT(*) INTO matching_claim_count
    FROM zarinpal_provider_evidence_claims claim_row
    WHERE claim_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
      AND BINARY claim_row.authority = BINARY NEW.authority
      AND BINARY claim_row.provider_ref_id = BINARY NEW.provider_ref_id
      AND BINARY claim_row.evidence_payload_hash = BINARY NEW.evidence_payload_hash
      AND BINARY claim_row.evidence_disposition = BINARY 'settled'
      AND claim_row.amount_irr = NEW.amount_irr
      AND BINARY claim_row.currency = BINARY NEW.currency;

    IF matching_claim_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal wallet top-up verification requires its exact settled provider evidence claim.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_wallet_top_up_verifications_update_guard
BEFORE UPDATE ON zarinpal_wallet_top_up_verifications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal wallet top-up verifications are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_wallet_top_up_verifications_delete_guard
BEFORE DELETE ON zarinpal_wallet_top_up_verifications
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal wallet top-up verifications are non-deletable.';
END
SQL);
    }

    private function replaceFindingGuard(bool $allowWalletTopUp): void
    {
        $verificationUnion = $allowWalletTopUp
            ? <<<'SQL'
            SELECT verification_row.id
            FROM zarinpal_payment_verifications verification_row
            WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
            UNION ALL
            SELECT verification_row.id
            FROM zarinpal_wallet_top_up_verifications verification_row
            WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
SQL
            : <<<'SQL'
            SELECT verification_row.id
            FROM zarinpal_payment_verifications verification_row
            WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
SQL;
        $compatibleUnion = $allowWalletTopUp
            ? <<<'SQL'
                SELECT verification_row.provider_ref_id, verification_row.evidence_payload_hash
                FROM zarinpal_payment_verifications verification_row
                WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
                UNION ALL
                SELECT verification_row.provider_ref_id, verification_row.evidence_payload_hash
                FROM zarinpal_wallet_top_up_verifications verification_row
                WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
SQL
            : <<<'SQL'
                SELECT verification_row.provider_ref_id, verification_row.evidence_payload_hash
                FROM zarinpal_payment_verifications verification_row
                WHERE verification_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
SQL;

        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_reconciliation_findings_insert_guard');
        DB::unprepared(<<<SQL
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
    ELSEIF NEW.finding_type = 'verified_promotion_authority_unavailable' THEN
        SELECT COUNT(*) INTO matching_evidence_count
        FROM zarinpal_verified_unsettled_evidence evidence_row
        WHERE evidence_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
          AND evidence_row.provider_ref_id = NEW.provider_ref_id
          AND evidence_row.provider_verify_code = NEW.provider_code
          AND evidence_row.evidence_payload_hash = NEW.evidence_hash
          AND evidence_row.reason_code = 'promotion_authority_unavailable';
        IF NEW.observed_result <> 'verified' OR matching_evidence_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal promotion-authority reconciliation finding requires matching immutable unsettled evidence.';
        END IF;
    ELSEIF NEW.finding_type = 'provider_verification_conflict' THEN
        SELECT COUNT(*) INTO accepted_evidence_count
        FROM (
{$verificationUnion}
            UNION ALL
            SELECT evidence_row.id
            FROM zarinpal_verified_unsettled_evidence evidence_row
            WHERE evidence_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
        ) accepted_rows;

        IF NEW.observed_result = 'verified' THEN
            SELECT COUNT(*) INTO compatible_evidence_count
            FROM (
{$compatibleUnion}
                UNION ALL
                SELECT evidence_row.provider_ref_id, evidence_row.evidence_payload_hash
                FROM zarinpal_verified_unsettled_evidence evidence_row
                WHERE evidence_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
            ) accepted_rows
            WHERE BINARY accepted_rows.provider_ref_id = BINARY NEW.provider_ref_id
              AND BINARY accepted_rows.evidence_payload_hash = BINARY NEW.evidence_hash;

            SELECT COUNT(*) INTO conflicting_claim_count
            FROM zarinpal_provider_evidence_claims claim_row
            INNER JOIN zarinpal_payment_requests request_row ON request_row.id = NEW.zarinpal_payment_request_id
            WHERE (
                claim_row.zarinpal_payment_request_id = NEW.zarinpal_payment_request_id
                OR BINARY claim_row.authority = BINARY request_row.authority
                OR BINARY claim_row.provider_ref_id = BINARY NEW.provider_ref_id
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
    }
};
