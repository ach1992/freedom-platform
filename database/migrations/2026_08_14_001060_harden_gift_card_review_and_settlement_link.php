<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_reviews_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_reviews_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_redemptions_update_guard');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_submissions_update_guard
BEFORE UPDATE ON gift_card_submissions
FOR EACH ROW
BEGIN
    DECLARE valid_review_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.submission_key <=> OLD.submission_key)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.gift_card_type_id <=> OLD.gift_card_type_id)
       OR NOT (NEW.gift_card_type_version_snapshot <=> OLD.gift_card_type_version_snapshot)
       OR NOT (NEW.gift_card_type_configuration_hash <=> OLD.gift_card_type_configuration_hash)
       OR NOT (NEW.user_id <=> OLD.user_id)
       OR NOT (NEW.encrypted_code <=> OLD.encrypted_code)
       OR NOT (NEW.code_lookup_hash <=> OLD.code_lookup_hash)
       OR NOT (NEW.code_lookup_key_version <=> OLD.code_lookup_key_version)
       OR NOT (NEW.masked_code <=> OLD.masked_code)
       OR NOT (NEW.private_image_reference <=> OLD.private_image_reference)
       OR NOT (NEW.telegram_file_id <=> OLD.telegram_file_id)
       OR NOT (NEW.telegram_file_unique_id <=> OLD.telegram_file_unique_id)
       OR NOT (NEW.image_content_hash <=> OLD.image_content_hash)
       OR NOT (NEW.claimed_face_value <=> OLD.claimed_face_value)
       OR NOT (NEW.claimed_currency <=> OLD.claimed_currency)
       OR NOT (NEW.claimed_brand <=> OLD.claimed_brand)
       OR NOT (NEW.claimed_region <=> OLD.claimed_region)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash)
       OR NOT (NEW.submitted_at <=> OLD.submitted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card submission evidence identity is immutable.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'submitted' AND NEW.state IN ('validating','pending_manual_review','rejected')) OR
        (OLD.state = 'validating' AND NEW.state IN ('valid_unreserved','reserving','redeeming','pending_manual_review','invalid','already_used','expired','provider_unavailable','rejected')) OR
        (OLD.state = 'valid_unreserved' AND NEW.state IN ('reserving','redeeming','pending_manual_review','rejected')) OR
        (OLD.state = 'reserving' AND NEW.state IN ('reserved','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'reserved' AND NEW.state IN ('redeeming','released','pending_manual_review','rejected')) OR
        (OLD.state = 'redeeming' AND NEW.state IN ('captured','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('redeeming','rejected'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card submission state transition is invalid.';
    END IF;

    IF OLD.state = 'pending_manual_review' AND NEW.state = 'redeeming' THEN
        SELECT COUNT(*) INTO valid_review_count
        FROM gift_card_reviews review_row
        WHERE review_row.gift_card_submission_id = OLD.id
          AND review_row.state = 'approved'
          AND review_row.decided_by_administrator_id IS NOT NULL
          AND review_row.decision_reason IS NOT NULL
          AND review_row.decided_at IS NOT NULL;
        IF valid_review_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card manual redeem requires an approved review.';
        END IF;
    END IF;

    IF OLD.state = 'pending_manual_review' AND NEW.state = 'rejected' THEN
        SELECT COUNT(*) INTO valid_review_count
        FROM gift_card_reviews review_row
        WHERE review_row.gift_card_submission_id = OLD.id
          AND review_row.state = 'rejected'
          AND review_row.decided_by_administrator_id IS NOT NULL
          AND review_row.decision_reason IS NOT NULL
          AND review_row.decided_at IS NOT NULL;
        IF valid_review_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card manual rejection requires a rejected review.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_reviews_update_guard
BEFORE UPDATE ON gift_card_reviews
FOR EACH ROW
BEGIN
    DECLARE valid_admin_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.gift_card_submission_id <=> OLD.gift_card_submission_id)
       OR NOT (NEW.reason_code <=> OLD.reason_code)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card review identity is immutable.';
    END IF;

    IF OLD.state = 'pending' AND NEW.state IN ('approved','rejected') THEN
        IF NEW.decided_by_administrator_id IS NULL
           OR NEW.decision_reason IS NULL
           OR CHAR_LENGTH(TRIM(NEW.decision_reason)) < 1
           OR NEW.decided_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card review decision requires administrator, reason and timestamp.';
        END IF;
        SELECT COUNT(*) INTO valid_admin_count
        FROM administrators administrator_row
        WHERE administrator_row.id = NEW.decided_by_administrator_id
          AND administrator_row.status = 'active';
        IF valid_admin_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card review decision requires an active administrator.';
        END IF;
    ELSEIF NOT (NEW.state <=> OLD.state)
       OR NOT (NEW.decided_by_administrator_id <=> OLD.decided_by_administrator_id)
       OR NOT (NEW.decision_reason <=> OLD.decision_reason)
       OR NOT (NEW.decided_at <=> OLD.decided_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card review decision transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_reviews_delete_guard
BEFORE DELETE ON gift_card_reviews
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card reviews are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_redemptions_update_guard
BEFORE UPDATE ON gift_card_redemptions
FOR EACH ROW
BEGIN
    DECLARE valid_settlement_count INT DEFAULT 0;

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

    IF OLD.purchase_settlement_id IS NULL AND NEW.purchase_settlement_id IS NOT NULL THEN
        SELECT COUNT(*) INTO valid_settlement_count
        FROM purchase_settlements settlement_row
        WHERE settlement_row.id = NEW.purchase_settlement_id
          AND settlement_row.payment_intent_id = OLD.payment_intent_id
          AND settlement_row.provider_code = 'gift_card'
          AND settlement_row.provider_transaction_id = SHA2(CONCAT(OLD.provider_code, CHAR(0), OLD.provider_redemption_id), 256)
          AND settlement_row.evidence_payload_hash = OLD.evidence_hash
          AND settlement_row.amount_irr = OLD.amount_irr
          AND settlement_row.currency = OLD.currency;
        IF valid_settlement_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card redemption settlement link does not match authoritative settlement.';
        END IF;
    ELSEIF NOT (NEW.purchase_settlement_id <=> OLD.purchase_settlement_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card redemption settlement link is immutable after first assignment.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('gift_card_reviews')->whereIn('state', ['approved', 'rejected'])->exists()
            || DB::table('gift_card_redemptions')->whereNotNull('purchase_settlement_id')->exists()) {
            throw new RuntimeException('Cannot roll back gift-card review/settlement hardening after decisions or captures exist.');
        }
        throw new RuntimeException('Forward-only gift-card authority hardening is intentionally not rolled back.');
    }
};