<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PAY-001 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_subject_snapshot_guard
BEFORE INSERT ON payment_method_eligibility_decisions
FOR EACH ROW
BEGIN
    DECLARE current_account_type VARCHAR(16) DEFAULT NULL;
    DECLARE current_account_status VARCHAR(16) DEFAULT NULL;
    DECLARE current_identity_status VARCHAR(16) DEFAULT 'unverified';
    DECLARE current_tier_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE current_tier_code VARCHAR(64) DEFAULT NULL;
    DECLARE current_agent_status VARCHAR(16) DEFAULT NULL;
    DECLARE current_tag_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE snapshot_tag_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;

    SELECT u.account_type, u.account_status
    INTO current_account_type, current_account_status
    FROM users u
    WHERE u.id = NEW.user_id
    FOR UPDATE;

    SELECT p.identity_verification_status, p.current_tier_id
    INTO current_identity_status, current_tier_id
    FROM customer_profiles p
    WHERE p.user_id = NEW.user_id
    FOR UPDATE;

    IF current_tier_id IS NOT NULL THEN
        SELECT t.code
        INTO current_tier_code
        FROM customer_tiers t
        WHERE t.id = current_tier_id;
    END IF;

    SELECT a.status
    INTO current_agent_status
    FROM agent_profiles a
    WHERE a.user_id = NEW.user_id
    FOR UPDATE;

    SELECT COUNT(*)
    INTO current_tag_count
    FROM customer_tag_assignments assignments
    INNER JOIN customer_tags tags ON tags.id = assignments.tag_id
    WHERE assignments.user_id = NEW.user_id
      AND assignments.removed_at IS NULL
      AND tags.is_active = 1;

    SET snapshot_tag_count = COALESCE(JSON_LENGTH(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.tag_codes')), 0);

    IF current_account_type IS NULL
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.account_type')), '') <> current_account_type
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.account_status')), '') <> current_account_status
       OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.identity_status')), '') <> current_identity_status
       OR (
            current_tier_code IS NULL
            AND COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.tier_code')), 'MISSING') <> 'NULL'
       )
       OR (
            current_tier_code IS NOT NULL
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.tier_code')), '') <> current_tier_code
       )
       OR (
            current_agent_status IS NULL
            AND COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.agent_status')), 'MISSING') <> 'NULL'
       )
       OR (
            current_agent_status IS NOT NULL
            AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.agent_status')), '') <> current_agent_status
       )
       OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.tag_codes')), 'MISSING') <> 'ARRAY'
       OR snapshot_tag_count <> current_tag_count
       OR EXISTS (
            SELECT 1
            FROM customer_tag_assignments assignments
            INNER JOIN customer_tags tags ON tags.id = assignments.tag_id
            WHERE assignments.user_id = NEW.user_id
              AND assignments.removed_at IS NULL
              AND tags.is_active = 1
              AND COALESCE(
                    JSON_CONTAINS(
                        JSON_EXTRACT(NEW.configuration_snapshot, '$.subject.tag_codes'),
                        JSON_QUOTE(tags.code)
                    ),
                    0
                  ) = 0
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility subject snapshot is stale.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_subject_snapshot_guard');
    }
};
