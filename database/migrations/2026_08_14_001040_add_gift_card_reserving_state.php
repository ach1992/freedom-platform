<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE gift_card_submissions DROP CONSTRAINT gift_card_submission_state_chk');
        DB::statement("ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_state_chk CHECK (`state` IN ('submitted','validating','valid_unreserved','reserving','reserved','redeeming','captured','pending_manual_review','invalid','already_used','expired','provider_unavailable','rejected','released'))");
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_update_guard');
        $this->createUpdateGuard(true);
    }

    public function down(): void
    {
        if (DB::table('gift_card_submissions')->where('state', 'reserving')->exists()) {
            throw new RuntimeException('Cannot remove gift-card reserving state while reservations are in flight.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_submissions_update_guard');
        DB::statement('ALTER TABLE gift_card_submissions DROP CONSTRAINT gift_card_submission_state_chk');
        DB::statement("ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_state_chk CHECK (`state` IN ('submitted','validating','valid_unreserved','reserved','redeeming','captured','pending_manual_review','invalid','already_used','expired','provider_unavailable','rejected','released'))");
        $this->createUpdateGuard(false);
    }

    private function createUpdateGuard(bool $withReserving): void
    {
        $validUnreserved = $withReserving
            ? "('reserving','redeeming','pending_manual_review','rejected')"
            : "('reserved','redeeming','pending_manual_review','rejected')";
        $reservingBranch = $withReserving
            ? "        (OLD.state = 'reserving' AND NEW.state IN ('reserved','pending_manual_review','provider_unavailable','rejected')) OR\n"
            : '';

        DB::unprepared(<<<SQL
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
        (OLD.state = 'validating' AND NEW.state IN ('valid_unreserved','reserving','redeeming','pending_manual_review','invalid','already_used','expired','provider_unavailable','rejected')) OR
        (OLD.state = 'valid_unreserved' AND NEW.state IN {$validUnreserved}) OR
{$reservingBranch}        (OLD.state = 'reserved' AND NEW.state IN ('redeeming','released','pending_manual_review','rejected')) OR
        (OLD.state = 'redeeming' AND NEW.state IN ('captured','pending_manual_review','provider_unavailable','rejected')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('redeeming','rejected'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card submission state transition is invalid.';
    END IF;
END
SQL);
    }
};
