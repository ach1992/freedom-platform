<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_image_shape_chk CHECK (
    (`private_image_reference` IS NULL
     AND `telegram_file_id` IS NULL
     AND `telegram_file_unique_id` IS NULL
     AND `image_content_hash` IS NULL)
    OR (`private_image_reference` IS NOT NULL AND `image_content_hash` IS NOT NULL)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE gift_card_submissions ADD CONSTRAINT gift_card_submission_code_shape_chk CHECK (
    (`encrypted_code` IS NULL
     AND `code_lookup_hash` IS NULL
     AND `code_lookup_key_version` IS NULL
     AND `masked_code` IS NULL)
    OR (`encrypted_code` IS NOT NULL
        AND `code_lookup_hash` IS NOT NULL
        AND `code_lookup_key_version` IS NOT NULL
        AND `masked_code` IS NOT NULL)
)
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_redemptions_insert_guard');
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
      AND event_row.provider_status = 'redeemed'
      AND event_row.provider_transaction_id = NEW.provider_redemption_id
      AND event_row.face_value = submission_row.claimed_face_value
      AND event_row.currency = 'IRR'
      AND event_row.brand = submission_row.claimed_brand
      AND (event_row.region <=> submission_row.claimed_region)
      AND event_row.evidence_hash = NEW.evidence_hash
      AND event_row.occurred_at >= submission_row.submitted_at
      AND NEW.redeemed_at = event_row.occurred_at
      AND NEW.amount_irr = intent_row.amount_irr
      AND NEW.currency = 'IRR';

    IF valid_count <> 1 OR NEW.purchase_settlement_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card redemption requires one exact authoritative post-submission redeem event and purchase authority.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (DB::table('gift_card_redemptions')->exists()) {
            throw new RuntimeException('Cannot roll back gift-card evidence timing after redemption exists.');
        }
        throw new RuntimeException('Forward-only gift-card evidence hardening is intentionally not rolled back.');
    }
};
