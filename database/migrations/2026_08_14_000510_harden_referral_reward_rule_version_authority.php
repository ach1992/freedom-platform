<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement REF-001 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_accruals_latest_rule_guard
BEFORE INSERT ON referral_reward_accruals
FOR EACH ROW
BEGIN
    DECLARE latest_count INT DEFAULT 0;

    SELECT COUNT(*) INTO latest_count
    FROM pricing_rule_versions selected_version
    INNER JOIN purchase_settlements settlement
      ON settlement.id = NEW.purchase_settlement_id
    WHERE selected_version.id = NEW.pricing_rule_version_id
      AND selected_version.version = (
          SELECT MAX(candidate.version)
          FROM pricing_rule_versions candidate
          WHERE candidate.pricing_rule_id = selected_version.pricing_rule_id
            AND candidate.created_at <= settlement.settled_at
      );

    IF latest_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward requires the latest rule version available at settlement time.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_accruals_latest_rule_guard');
    }
};
