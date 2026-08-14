<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_update_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_submissions_update_guard
BEFORE UPDATE ON gift_card_submissions
FOR EACH ROW
BEGIN
    DECLARE valid_review_count INT DEFAULT 0;
    DECLARE valid_release_count INT DEFAULT 0;

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
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('redeeming','rejected')) OR
        (OLD.state = 'rejected' AND NEW.state = 'released')
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

    IF NEW.state = 'released' AND OLD.state IN ('reserved','rejected') THEN
        SELECT COUNT(*) INTO valid_release_count
        FROM gift_card_provider_events event_row
        INNER JOIN gift_card_types type_row ON type_row.id = OLD.gift_card_type_id
        WHERE event_row.gift_card_submission_id = OLD.id
          AND event_row.provider_code = type_row.provider_code
          AND event_row.operation = 'release'
          AND event_row.outcome = 'success'
          AND event_row.provider_status IN ('released','canceled','cancelled')
          AND event_row.occurred_at >= OLD.submitted_at;
        IF valid_release_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card release requires one authoritative provider release event.';
        END IF;
        IF EXISTS (
            SELECT 1 FROM gift_card_redemptions redemption_row
            WHERE redemption_row.gift_card_submission_id = OLD.id
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Redeemed gift-card submission cannot be released.';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('gift_card_submissions')->where('state', 'released')->exists()) {
            throw new RuntimeException('Cannot roll back gift-card release authority after release exists.');
        }
        throw new RuntimeException('Forward-only gift-card release hardening is intentionally not rolled back.');
    }
};
