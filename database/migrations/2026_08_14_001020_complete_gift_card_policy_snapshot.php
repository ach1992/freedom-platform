<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gift_card_types ADD COLUMN manual_approval_limit_face_value BIGINT NULL AFTER verification_mode');
        DB::statement("ALTER TABLE gift_card_types ADD CONSTRAINT gift_card_type_manual_limit_chk CHECK ((`verification_mode` = 'automatic_with_manual_approval_above_limit' AND `manual_approval_limit_face_value` IS NOT NULL AND `manual_approval_limit_face_value` > 0) OR (`verification_mode` <> 'automatic_with_manual_approval_above_limit' AND `manual_approval_limit_face_value` IS NULL))");

        DB::statement('ALTER TABLE gift_card_submissions ADD COLUMN gift_card_type_version_snapshot BIGINT UNSIGNED NOT NULL AFTER gift_card_type_id');
        DB::statement('ALTER TABLE gift_card_submissions ADD COLUMN gift_card_type_configuration_hash CHAR(64) NOT NULL AFTER gift_card_type_version_snapshot');
        DB::statement('ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_type_hash_chk CHECK (CHAR_LENGTH(`gift_card_type_configuration_hash`) = 64 AND `gift_card_type_version_snapshot` > 0)');
        DB::statement('ALTER TABLE gift_card_submissions ADD UNIQUE gift_card_submission_image_hash_unique (`image_content_hash`)');
        DB::statement('ALTER TABLE gift_card_submissions ADD UNIQUE gift_card_submission_telegram_unique (`telegram_file_unique_id`)');

        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_types_update_guard');

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
       OR NOT (NEW.manual_approval_limit_face_value <=> OLD.manual_approval_limit_face_value)
       OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.version <=> OLD.version)
       OR NOT (NEW.configuration_hash <=> OLD.configuration_hash)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card type configuration identity is immutable.';
    END IF;
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
      AND type_row.version = NEW.gift_card_type_version_snapshot
      AND type_row.configuration_hash = NEW.gift_card_type_configuration_hash
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
    }

    public function down(): void
    {
        if (DB::table('gift_card_submissions')->exists()) {
            throw new RuntimeException('Cannot roll back completed gift-card policy snapshot while submissions exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_types_update_guard');
        DB::statement('ALTER TABLE gift_card_submissions DROP INDEX gift_card_submission_telegram_unique');
        DB::statement('ALTER TABLE gift_card_submissions DROP INDEX gift_card_submission_image_hash_unique');
        DB::statement('ALTER TABLE gift_card_submissions DROP CONSTRAINT gift_card_submission_type_hash_chk');
        DB::statement('ALTER TABLE gift_card_submissions DROP COLUMN gift_card_type_configuration_hash');
        DB::statement('ALTER TABLE gift_card_submissions DROP COLUMN gift_card_type_version_snapshot');
        DB::statement('ALTER TABLE gift_card_types DROP CONSTRAINT gift_card_type_manual_limit_chk');
        DB::statement('ALTER TABLE gift_card_types DROP COLUMN manual_approval_limit_face_value');
    }
};
