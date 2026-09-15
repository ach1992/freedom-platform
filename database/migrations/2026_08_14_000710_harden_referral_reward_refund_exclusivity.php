<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement REF-001 DAT-002 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_lifecycle_release_refund_guard
BEFORE INSERT ON referral_reward_lifecycle_events
FOR EACH ROW
BEGIN
    DECLARE refund_count BIGINT UNSIGNED DEFAULT 0;

    IF NEW.event_type = 'released' THEN
        SELECT COUNT(*) INTO refund_count
        FROM purchase_refunds refund_row
        INNER JOIN referral_rewards reward_row
          ON reward_row.purchase_settlement_id = refund_row.purchase_settlement_id
        WHERE reward_row.id = NEW.referral_reward_id;

        IF refund_count > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refunded purchase cannot create a referral reward release event.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_release_refund_guard
BEFORE UPDATE ON referral_rewards
FOR EACH ROW
BEGIN
    DECLARE refund_count BIGINT UNSIGNED DEFAULT 0;

    IF OLD.state = 'pending' AND NEW.state = 'released' THEN
        SELECT COUNT(*) INTO refund_count
        FROM purchase_refunds
        WHERE purchase_settlement_id = OLD.purchase_settlement_id;

        IF refund_count > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refunded purchase cannot release a pending referral reward.';
        END IF;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_release_refund_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_lifecycle_release_refund_guard');
    }
};
