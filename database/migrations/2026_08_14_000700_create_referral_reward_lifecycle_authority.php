<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS referral_rewards_update_guard');
        DB::statement('ALTER TABLE referral_rewards DROP CONSTRAINT referral_reward_state_chk');

        Schema::table('referral_rewards', function (Blueprint $table): void {
            $table->foreignId('release_ledger_transaction_id')->nullable()->unique()->after('transferable')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('reversal_ledger_transaction_id')->nullable()->unique()->after('release_ledger_transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('purchase_refund_id')->nullable()->after('reversal_ledger_transaction_id')->constrained('purchase_refunds')->restrictOnDelete();
            $table->dateTime('released_at', 6)->nullable()->after('purchase_refund_id');
            $table->dateTime('canceled_at', 6)->nullable()->after('released_at');
            $table->dateTime('reversed_at', 6)->nullable()->after('canceled_at');
            $table->index(['purchase_refund_id', 'state'], 'referral_reward_refund_state_idx');
        });

        DB::statement("ALTER TABLE referral_rewards ADD CONSTRAINT referral_reward_state_chk CHECK (`state` IN ('pending','released','canceled','reversed'))");
        DB::statement(<<<'SQL'
ALTER TABLE referral_rewards ADD CONSTRAINT referral_reward_lifecycle_shape_chk CHECK (
    (`state` = 'pending'
        AND `release_ledger_transaction_id` IS NULL
        AND `reversal_ledger_transaction_id` IS NULL
        AND `purchase_refund_id` IS NULL
        AND `released_at` IS NULL
        AND `canceled_at` IS NULL
        AND `reversed_at` IS NULL)
    OR (`state` = 'released'
        AND `release_ledger_transaction_id` IS NOT NULL
        AND `reversal_ledger_transaction_id` IS NULL
        AND `purchase_refund_id` IS NULL
        AND `released_at` IS NOT NULL
        AND `canceled_at` IS NULL
        AND `reversed_at` IS NULL)
    OR (`state` = 'canceled'
        AND `release_ledger_transaction_id` IS NULL
        AND `reversal_ledger_transaction_id` IS NULL
        AND `purchase_refund_id` IS NOT NULL
        AND `released_at` IS NULL
        AND `canceled_at` IS NOT NULL
        AND `reversed_at` IS NULL)
    OR (`state` = 'reversed'
        AND `release_ledger_transaction_id` IS NOT NULL
        AND `reversal_ledger_transaction_id` IS NOT NULL
        AND `purchase_refund_id` IS NOT NULL
        AND `released_at` IS NOT NULL
        AND `canceled_at` IS NULL
        AND `reversed_at` IS NOT NULL)
)
SQL);

        Schema::create('referral_reward_lifecycle_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('referral_reward_id')->constrained('referral_rewards')->restrictOnDelete();
            $table->string('event_type', 16);
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('purchase_refund_id')->nullable()->constrained('purchase_refunds')->restrictOnDelete();
            $table->dateTime('occurred_at', 6);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['referral_reward_id', 'event_type'], 'referral_reward_lifecycle_event_unique');
            $table->index(['purchase_refund_id', 'event_type'], 'referral_reward_lifecycle_refund_idx');
        });
        DB::statement("ALTER TABLE referral_reward_lifecycle_events ADD CONSTRAINT referral_reward_lifecycle_event_type_chk CHECK (`event_type` IN ('released','canceled','reversed'))");
        DB::statement(<<<'SQL'
ALTER TABLE referral_reward_lifecycle_events ADD CONSTRAINT referral_reward_lifecycle_event_shape_chk CHECK (
    (`event_type` = 'released' AND `ledger_transaction_id` IS NOT NULL AND `purchase_refund_id` IS NULL)
    OR (`event_type` = 'canceled' AND `ledger_transaction_id` IS NULL AND `purchase_refund_id` IS NOT NULL)
    OR (`event_type` = 'reversed' AND `ledger_transaction_id` IS NOT NULL AND `purchase_refund_id` IS NOT NULL)
)
SQL);

        $this->createLifecycleEventGuards();
        $this->createRewardLifecycleGuards();
    }

    public function down(): void
    {
        if (DB::table('referral_reward_lifecycle_events')->exists()
            || DB::table('referral_rewards')->where('state', '<>', 'pending')->exists()) {
            throw new RuntimeException('Cannot roll back referral reward lifecycle authority after lifecycle state exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS referral_rewards_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_lifecycle_events_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_lifecycle_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_lifecycle_events_insert_guard');
        Schema::dropIfExists('referral_reward_lifecycle_events');

        DB::statement('ALTER TABLE referral_rewards DROP CONSTRAINT referral_reward_lifecycle_shape_chk');
        DB::statement('ALTER TABLE referral_rewards DROP CONSTRAINT referral_reward_state_chk');
        Schema::table('referral_rewards', function (Blueprint $table): void {
            $table->dropIndex('referral_reward_refund_state_idx');
            $table->dropConstrainedForeignId('purchase_refund_id');
            $table->dropConstrainedForeignId('reversal_ledger_transaction_id');
            $table->dropConstrainedForeignId('release_ledger_transaction_id');
            $table->dropColumn(['released_at', 'canceled_at', 'reversed_at']);
        });
        DB::statement("ALTER TABLE referral_rewards ADD CONSTRAINT referral_reward_state_chk CHECK (`state` = 'pending')");
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_rewards_update_guard
BEFORE UPDATE ON referral_rewards FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pending referral rewards are immutable until lifecycle authority is installed.';
END
SQL);
    }

    private function createLifecycleEventGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_lifecycle_events_insert_guard
BEFORE INSERT ON referral_reward_lifecycle_events
FOR EACH ROW
BEGIN
    DECLARE reward_state VARCHAR(32) DEFAULT NULL;
    DECLARE reward_public_id CHAR(26) DEFAULT NULL;
    DECLARE reward_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE reward_recipient_user_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE reward_amount BIGINT DEFAULT NULL;
    DECLARE reward_release_at DATETIME(6) DEFAULT NULL;
    DECLARE reward_release_ledger_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_ledger_count INT DEFAULT 0;
    DECLARE valid_wallet_entry_count INT DEFAULT 0;
    DECLARE valid_system_entry_count INT DEFAULT 0;
    DECLARE valid_refund_count INT DEFAULT 0;

    SELECT state, public_id, purchase_settlement_id, recipient_user_id, amount_irr, release_at, release_ledger_transaction_id
    INTO reward_state, reward_public_id, reward_settlement_id, reward_recipient_user_id, reward_amount, reward_release_at, reward_release_ledger_id
    FROM referral_rewards
    WHERE id = NEW.referral_reward_id
    FOR UPDATE;

    IF reward_state IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward lifecycle event requires an existing reward.';
    END IF;

    IF NEW.event_type = 'released' THEN
        IF reward_state <> 'pending' OR NEW.purchase_refund_id IS NOT NULL OR NEW.ledger_transaction_id IS NULL OR NEW.occurred_at < reward_release_at THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward release event is not eligible.';
        END IF;

        SELECT COUNT(*) INTO valid_ledger_count
        FROM ledger_transactions t
        WHERE t.id = NEW.ledger_transaction_id
          AND t.transaction_type = 'referral_reward_release'
          AND t.source_type = 'referral_reward'
          AND t.source_id = reward_public_id
          AND t.expected_total_irr = reward_amount
          AND t.posted_debit_irr = reward_amount
          AND t.posted_credit_irr = reward_amount
          AND t.entry_count = 2
          AND t.finalized_at IS NOT NULL;

        SELECT COUNT(*) INTO valid_wallet_entry_count
        FROM ledger_entries e
        INNER JOIN ledger_accounts a ON a.id = e.ledger_account_id
        WHERE e.ledger_transaction_id = NEW.ledger_transaction_id
          AND e.direction = 'credit'
          AND e.amount_irr = reward_amount
          AND a.account_class = 'liability'
          AND a.owner_user_id = reward_recipient_user_id
          AND a.wallet_bucket = 'promotional'
          AND a.currency = 'IRR';

        SELECT COUNT(*) INTO valid_system_entry_count
        FROM ledger_entries e
        INNER JOIN ledger_accounts a ON a.id = e.ledger_account_id
        WHERE e.ledger_transaction_id = NEW.ledger_transaction_id
          AND e.direction = 'debit'
          AND e.amount_irr = reward_amount
          AND a.code = 'system.referral.reward.expense'
          AND a.account_class = 'expense'
          AND a.owner_user_id IS NULL
          AND a.wallet_bucket IS NULL
          AND a.currency = 'IRR';

        IF valid_ledger_count <> 1 OR valid_wallet_entry_count <> 1 OR valid_system_entry_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward release event requires one matching balanced ledger effect.';
        END IF;
    ELSEIF NEW.event_type = 'canceled' THEN
        IF reward_state <> 'pending' OR NEW.ledger_transaction_id IS NOT NULL OR NEW.purchase_refund_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward cancellation event is invalid.';
        END IF;

        SELECT COUNT(*) INTO valid_refund_count
        FROM purchase_refunds r
        WHERE r.id = NEW.purchase_refund_id
          AND r.purchase_settlement_id = reward_settlement_id;
        IF valid_refund_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward cancellation requires authoritative purchase refund linkage.';
        END IF;
    ELSEIF NEW.event_type = 'reversed' THEN
        IF reward_state <> 'released' OR reward_release_ledger_id IS NULL OR NEW.ledger_transaction_id IS NULL OR NEW.purchase_refund_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward reversal event is invalid.';
        END IF;

        SELECT COUNT(*) INTO valid_refund_count
        FROM purchase_refunds r
        WHERE r.id = NEW.purchase_refund_id
          AND r.purchase_settlement_id = reward_settlement_id;

        SELECT COUNT(*) INTO valid_ledger_count
        FROM ledger_transactions t
        WHERE t.id = NEW.ledger_transaction_id
          AND t.id <> reward_release_ledger_id
          AND t.transaction_type = 'referral_reward_reversal'
          AND t.source_type = 'referral_reward_reversal'
          AND t.source_id = reward_public_id
          AND t.expected_total_irr = reward_amount
          AND t.posted_debit_irr = reward_amount
          AND t.posted_credit_irr = reward_amount
          AND t.entry_count = 2
          AND t.finalized_at IS NOT NULL;

        SELECT COUNT(*) INTO valid_wallet_entry_count
        FROM ledger_entries e
        INNER JOIN ledger_accounts a ON a.id = e.ledger_account_id
        WHERE e.ledger_transaction_id = NEW.ledger_transaction_id
          AND e.direction = 'debit'
          AND e.amount_irr = reward_amount
          AND a.account_class = 'liability'
          AND a.owner_user_id = reward_recipient_user_id
          AND a.wallet_bucket = 'promotional'
          AND a.currency = 'IRR';

        SELECT COUNT(*) INTO valid_system_entry_count
        FROM ledger_entries e
        INNER JOIN ledger_accounts a ON a.id = e.ledger_account_id
        WHERE e.ledger_transaction_id = NEW.ledger_transaction_id
          AND e.direction = 'credit'
          AND e.amount_irr = reward_amount
          AND a.code = 'system.referral.reward.expense'
          AND a.account_class = 'expense'
          AND a.owner_user_id IS NULL
          AND a.wallet_bucket IS NULL
          AND a.currency = 'IRR';

        IF valid_refund_count <> 1 OR valid_ledger_count <> 1 OR valid_wallet_entry_count <> 1 OR valid_system_entry_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward reversal requires matching refund and compensating ledger authority.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward lifecycle event type is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_lifecycle_events_update_guard
BEFORE UPDATE ON referral_reward_lifecycle_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward lifecycle events are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_lifecycle_events_delete_guard
BEFORE DELETE ON referral_reward_lifecycle_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward lifecycle events are non-deletable.';
END
SQL);
    }

    private function createRewardLifecycleGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_rewards_update_guard
BEFORE UPDATE ON referral_rewards
FOR EACH ROW
BEGIN
    DECLARE matching_event_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.accrual_id <=> OLD.accrual_id)
       OR NOT (NEW.purchase_settlement_id <=> OLD.purchase_settlement_id)
       OR NOT (NEW.recipient_role <=> OLD.recipient_role)
       OR NOT (NEW.recipient_user_id <=> OLD.recipient_user_id)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.release_at <=> OLD.release_at)
       OR NOT (NEW.expires_at <=> OLD.expires_at)
       OR NOT (NEW.transferable <=> OLD.transferable)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward financial identity is immutable.';
    END IF;

    IF NEW.state = OLD.state THEN
        IF NOT (NEW.release_ledger_transaction_id <=> OLD.release_ledger_transaction_id)
           OR NOT (NEW.reversal_ledger_transaction_id <=> OLD.reversal_ledger_transaction_id)
           OR NOT (NEW.purchase_refund_id <=> OLD.purchase_refund_id)
           OR NOT (NEW.released_at <=> OLD.released_at)
           OR NOT (NEW.canceled_at <=> OLD.canceled_at)
           OR NOT (NEW.reversed_at <=> OLD.reversed_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward lifecycle authority cannot mutate without a state transition.';
        END IF;
    ELSEIF OLD.state = 'pending' AND NEW.state = 'released' THEN
        IF NEW.release_ledger_transaction_id IS NULL
           OR NEW.reversal_ledger_transaction_id IS NOT NULL
           OR NEW.purchase_refund_id IS NOT NULL
           OR NEW.released_at IS NULL
           OR NEW.canceled_at IS NOT NULL
           OR NEW.reversed_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward release state shape is invalid.';
        END IF;

        SELECT COUNT(*) INTO matching_event_count
        FROM referral_reward_lifecycle_events e
        WHERE e.referral_reward_id = OLD.id
          AND e.event_type = 'released'
          AND e.ledger_transaction_id = NEW.release_ledger_transaction_id
          AND e.purchase_refund_id IS NULL
          AND e.occurred_at = NEW.released_at;
        IF matching_event_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward release requires immutable lifecycle event authority.';
        END IF;
    ELSEIF OLD.state = 'pending' AND NEW.state = 'canceled' THEN
        IF NEW.release_ledger_transaction_id IS NOT NULL
           OR NEW.reversal_ledger_transaction_id IS NOT NULL
           OR NEW.purchase_refund_id IS NULL
           OR NEW.released_at IS NOT NULL
           OR NEW.canceled_at IS NULL
           OR NEW.reversed_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward cancellation state shape is invalid.';
        END IF;

        SELECT COUNT(*) INTO matching_event_count
        FROM referral_reward_lifecycle_events e
        WHERE e.referral_reward_id = OLD.id
          AND e.event_type = 'canceled'
          AND e.ledger_transaction_id IS NULL
          AND e.purchase_refund_id = NEW.purchase_refund_id
          AND e.occurred_at = NEW.canceled_at;
        IF matching_event_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward cancellation requires immutable lifecycle event authority.';
        END IF;
    ELSEIF OLD.state = 'released' AND NEW.state = 'reversed' THEN
        IF NEW.release_ledger_transaction_id IS NULL
           OR NOT (NEW.release_ledger_transaction_id <=> OLD.release_ledger_transaction_id)
           OR NEW.reversal_ledger_transaction_id IS NULL
           OR NEW.purchase_refund_id IS NULL
           OR NEW.released_at IS NULL
           OR NOT (NEW.released_at <=> OLD.released_at)
           OR NEW.canceled_at IS NOT NULL
           OR NEW.reversed_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward reversal state shape is invalid.';
        END IF;

        SELECT COUNT(*) INTO matching_event_count
        FROM referral_reward_lifecycle_events e
        WHERE e.referral_reward_id = OLD.id
          AND e.event_type = 'reversed'
          AND e.ledger_transaction_id = NEW.reversal_ledger_transaction_id
          AND e.purchase_refund_id = NEW.purchase_refund_id
          AND e.occurred_at = NEW.reversed_at;
        IF matching_event_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward reversal requires immutable lifecycle event authority.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward state transition is invalid.';
    END IF;
END
SQL);
    }
};
