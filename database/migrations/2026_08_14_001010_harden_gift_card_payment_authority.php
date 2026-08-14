<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gift_card_submissions ADD COLUMN code_lookup_key_version INT UNSIGNED NULL AFTER code_lookup_hash');
        DB::statement('ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_code_key_chk CHECK ((`code_lookup_hash` IS NULL AND `code_lookup_key_version` IS NULL) OR (`code_lookup_hash` IS NOT NULL AND `code_lookup_key_version` IS NOT NULL AND `code_lookup_key_version` > 0))');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_types_update_guard
BEFORE UPDATE ON gift_card_types
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.type_code <=> OLD.type_code)
       OR NOT (NEW.display_name <=> OLD.display_name)
       OR NOT (NEW.brand <=> OLD.brand)
       OR NOT (NEW.region <=> OLD.region)
       OR NOT (NEW.face_currency <=> OLD.face_currency)
       OR NOT (NEW.submission_mode <=> OLD.submission_mode)
       OR NOT (NEW.verification_mode <=> OLD.verification_mode)
       OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.version <=> OLD.version)
       OR NOT (NEW.configuration_hash <=> OLD.configuration_hash)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card type configuration identity is immutable.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_types_delete_guard
BEFORE DELETE ON gift_card_types
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card type configurations are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_submissions_insert_guard
BEFORE INSERT ON gift_card_submissions
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_count
    FROM payment_intents intent_row
    INNER JOIN gift_card_types type_row ON type_row.id = NEW.gift_card_type_id
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.user_id = NEW.user_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'gift_card'
      AND intent_row.provider_code = 'gift_card'
      AND intent_row.state = 'awaiting_user_action'
      AND intent_row.captured_at IS NULL
      AND type_row.active = 1
      AND type_row.brand = NEW.claimed_brand
      AND (type_row.region <=> NEW.claimed_region)
      AND type_row.face_currency = NEW.claimed_currency
      AND (
        (type_row.submission_mode = 'image_only' AND NEW.encrypted_code IS NULL AND NEW.code_lookup_hash IS NULL AND NEW.private_image_reference IS NOT NULL)
        OR (type_row.submission_mode = 'code_only' AND NEW.encrypted_code IS NOT NULL AND NEW.code_lookup_hash IS NOT NULL AND NEW.private_image_reference IS NULL)
        OR (type_row.submission_mode = 'either' AND ((NEW.encrypted_code IS NOT NULL AND NEW.code_lookup_hash IS NOT NULL) OR NEW.private_image_reference IS NOT NULL))
        OR (type_row.submission_mode = 'both' AND NEW.encrypted_code IS NOT NULL AND NEW.code_lookup_hash IS NOT NULL AND NEW.private_image_reference IS NOT NULL)
      );

    IF valid_count <> 1 OR NEW.state <> 'submitted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card submission does not match one eligible purchase/type authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_submissions_update_guard
BEFORE UPDATE ON gift_card_submissions
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.submission_key <=> OLD.submission_key)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.gift_card_type_id <=> OLD.gift_card_type_id)
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
        (OLD.state = 'validating' AND NEW.state IN ('valid_unreserved','reserved','redeeming','pending_manual_review','invalid','already_used','expired','provider_unavailable','rejected')) OR
        (OLD.state = 'valid_unreserved' AND NEW.state IN ('reserved','redeeming','pending_manual_review','rejected')) OR
        (OLD.state = 'reserved' AND NEW.state IN ('redeeming','released','pending_manual_review','rejected')) OR
        (OLD.state = 'redeeming' AND NEW.state IN ('captured','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('redeeming','rejected'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card submission state transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER gift_card_redemptions_insert_guard
BEFORE INSERT ON gift_card_redemptions
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_count
    FROM gift_card_submissions submission_row
    INNER JOIN gift_card_types type_row ON type_row.id = submission_row.gift_card_type_id
    INNER JOIN payment_intents intent_row ON intent_row.id = submission_row.payment_intent_id
    INNER JOIN gift_card_provider_events event_row ON event_row.id = NEW.provider_event_row_id
    WHERE submission_row.id = NEW.gift_card_submission_id
      AND submission_row.payment_intent_id = NEW.payment_intent_id
      AND submission_row.state = 'redeeming'
      AND submission_row.claimed_currency = 'IRR'
      AND submission_row.claimed_face_value = intent_row.amount_irr
      AND type_row.face_currency = 'IRR'
      AND type_row.brand = submission_row.claimed_brand
      AND (type_row.region <=> submission_row.claimed_region)
      AND type_row.provider_code = NEW.provider_code
      AND intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.payment_method_code = 'gift_card'
      AND intent_row.provider_code = 'gift_card'
      AND intent_row.state IN ('submitted','verifying','pending_manual_review','authorized')
      AND intent_row.captured_at IS NULL
      AND event_row.gift_card_submission_id = submission_row.id
      AND event_row.provider_code = NEW.provider_code
      AND event_row.operation = 'redeem'
      AND event_row.outcome = 'success'
      AND event_row.provider_transaction_id = NEW.provider_redemption_id
      AND event_row.face_value = submission_row.claimed_face_value
      AND event_row.currency = 'IRR'
      AND event_row.brand = submission_row.claimed_brand
      AND (event_row.region <=> submission_row.claimed_region)
      AND event_row.evidence_hash = NEW.evidence_hash
      AND NEW.amount_irr = intent_row.amount_irr
      AND NEW.currency = 'IRR';

    IF valid_count <> 1 OR NEW.purchase_settlement_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card redemption requires one exact authoritative redeem event and purchase authority.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('gift_card_submissions')->exists()) {
            throw new RuntimeException('Cannot roll back hardened gift-card authority while submissions exist.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_redemptions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_types_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_types_update_guard');
        DB::statement('ALTER TABLE gift_card_submissions DROP CONSTRAINT gift_card_submission_code_key_chk');
        DB::statement('ALTER TABLE gift_card_submissions DROP COLUMN code_lookup_key_version');
    }
};
