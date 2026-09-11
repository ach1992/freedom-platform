<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ONB-003 CHN-001 ACL-002 SEC-001 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('channel_membership_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('rule_key', 64)->unique();
            $table->string('action', 32)->nullable();
            $table->string('audience', 16);
            $table->string('tier_code', 32)->nullable();
            $table->foreign('tier_code', 'channel_membership_rule_tier_fk')
                ->references('code')->on('customer_tiers')->restrictOnDelete();
            $table->foreignId('customer_tag_id')->nullable()->constrained('customer_tags')->restrictOnDelete();
            $table->foreignId('plan_offering_id')->nullable()->constrained('plan_offerings')->restrictOnDelete();
            $table->string('match_mode', 8);
            $table->string('failure_policy', 16);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->dateTime('effective_from', 6)->nullable();
            $table->dateTime('effective_until', 6)->nullable();
            $table->string('state', 16)->default('draft');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['state', 'action', 'priority', 'id'], 'channel_membership_rule_resolution_idx');
            $table->index(['audience', 'state', 'id'], 'channel_membership_rule_audience_idx');
        });

        Schema::create('channel_membership_rule_channels', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('channel_membership_rule_id');
            $table->foreign('channel_membership_rule_id', 'membership_rule_channel_rule_fk')
                ->references('id')->on('channel_membership_rules')->restrictOnDelete();
            $table->unsignedBigInteger('required_channel_id');
            $table->foreign('required_channel_id', 'membership_rule_channel_required_fk')
                ->references('id')->on('required_channels')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->dateTime('created_at', 6);
            $table->unique(['channel_membership_rule_id', 'required_channel_id'], 'channel_membership_rule_channel_unique');
            $table->unique(['channel_membership_rule_id', 'sort_order'], 'channel_membership_rule_order_unique');
            $table->index(['required_channel_id', 'channel_membership_rule_id'], 'channel_membership_rule_channel_reverse_idx');
        });

        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_key_chk CHECK (`rule_key` REGEXP '^[a-z][a-z0-9_.-]{2,63}$')");
        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_action_chk CHECK (`action` IS NULL OR `action` IN ('bot_entry','trial','purchase','gift_code_use','referral_reward','ticket_creation','service_view','support_view'))");
        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_audience_chk CHECK (`audience` IN ('customers','agents','both'))");
        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_selector_chk CHECK ((`tier_code` IS NULL AND `customer_tag_id` IS NULL) OR `audience` = 'customers')");
        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_offering_scope_chk CHECK (`plan_offering_id` IS NULL OR `action` IN ('trial','purchase'))");
        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_match_chk CHECK (`match_mode` IN ('all','any'))");
        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_failure_chk CHECK (`failure_policy` IN ('fail_open','fail_closed','manual_review'))");
        DB::statement('ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_window_chk CHECK (`effective_from` IS NULL OR `effective_until` IS NULL OR `effective_until` > `effective_from`)');
        DB::statement("ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_state_chk CHECK (`state` IN ('draft','active','disabled'))");
        DB::statement('ALTER TABLE channel_membership_rules ADD CONSTRAINT channel_membership_rule_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE channel_membership_rule_channels ADD CONSTRAINT channel_membership_rule_channel_order_chk CHECK (`sort_order` <= 31)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER channel_membership_rules_insert_guard
BEFORE INSERT ON channel_membership_rules
FOR EACH ROW
BEGIN
    IF NEW.state <> 'draft' OR NEW.version <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership rule must be created as draft version 1.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER channel_membership_rules_update_guard
BEFORE UPDATE ON channel_membership_rules
FOR EACH ROW
BEGIN
    DECLARE configured_channel_count INT DEFAULT 0;
    DECLARE active_channel_count INT DEFAULT 0;
    DECLARE minimum_channel_order INT DEFAULT NULL;
    DECLARE maximum_channel_order INT DEFAULT NULL;
    DECLARE reference_count INT DEFAULT 0;

    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule version must advance exactly once.';
    END IF;

    IF OLD.state = 'active' AND NEW.state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram membership rule must be disabled before mutation.';
    END IF;

    IF OLD.state = 'active' AND NEW.state NOT IN ('active', 'disabled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram membership rule can only transition to disabled.';
    END IF;

    IF OLD.state = 'disabled' AND NEW.state = 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disabled Telegram membership rule cannot return to draft.';
    END IF;

    IF OLD.state = 'active' AND (
        NOT (OLD.rule_key <=> NEW.rule_key)
        OR NOT (OLD.action <=> NEW.action)
        OR NOT (OLD.audience <=> NEW.audience)
        OR NOT (OLD.tier_code <=> NEW.tier_code)
        OR NOT (OLD.customer_tag_id <=> NEW.customer_tag_id)
        OR NOT (OLD.plan_offering_id <=> NEW.plan_offering_id)
        OR NOT (OLD.match_mode <=> NEW.match_mode)
        OR NOT (OLD.failure_policy <=> NEW.failure_policy)
        OR NOT (OLD.priority <=> NEW.priority)
        OR NOT (OLD.effective_from <=> NEW.effective_from)
        OR NOT (OLD.effective_until <=> NEW.effective_until)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram membership-rule definition is immutable.';
    END IF;

    IF NEW.state = 'active' AND OLD.state <> 'active' THEN
        IF NOT (OLD.rule_key <=> NEW.rule_key)
            OR NOT (OLD.action <=> NEW.action)
            OR NOT (OLD.audience <=> NEW.audience)
            OR NOT (OLD.tier_code <=> NEW.tier_code)
            OR NOT (OLD.customer_tag_id <=> NEW.customer_tag_id)
            OR NOT (OLD.plan_offering_id <=> NEW.plan_offering_id)
            OR NOT (OLD.match_mode <=> NEW.match_mode)
            OR NOT (OLD.failure_policy <=> NEW.failure_policy)
            OR NOT (OLD.priority <=> NEW.priority)
            OR NOT (OLD.effective_from <=> NEW.effective_from)
            OR NOT (OLD.effective_until <=> NEW.effective_until) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule activation cannot change definition.';
        END IF;

        SELECT COUNT(*), COALESCE(SUM(channel_row.state = 'active'), 0),
               MIN(rule_channel.sort_order), MAX(rule_channel.sort_order)
          INTO configured_channel_count, active_channel_count, minimum_channel_order, maximum_channel_order
          FROM channel_membership_rule_channels rule_channel
          INNER JOIN required_channels channel_row ON channel_row.id = rule_channel.required_channel_id
         WHERE rule_channel.channel_membership_rule_id = OLD.id;

        IF configured_channel_count < 1
            OR active_channel_count <> configured_channel_count
            OR minimum_channel_order <> 0
            OR maximum_channel_order <> configured_channel_count - 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership rule requires an ordered set of active configured channels before activation.';
        END IF;

        IF NEW.tier_code IS NOT NULL THEN
            SELECT COUNT(*) INTO reference_count FROM customer_tiers
             WHERE code = NEW.tier_code AND is_active = 1;
            IF reference_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule tier is unavailable for activation.';
            END IF;
        END IF;

        IF NEW.customer_tag_id IS NOT NULL THEN
            SELECT COUNT(*) INTO reference_count FROM customer_tags
             WHERE id = NEW.customer_tag_id AND is_active = 1;
            IF reference_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule customer tag is unavailable for activation.';
            END IF;
        END IF;

        IF NEW.plan_offering_id IS NOT NULL THEN
            SELECT COUNT(*) INTO reference_count FROM plan_offerings
             WHERE id = NEW.plan_offering_id AND state = 'active';
            IF reference_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule Plan Offering is unavailable for activation.';
            END IF;
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER channel_membership_rules_delete_guard
BEFORE DELETE ON channel_membership_rules
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership rules are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER channel_membership_rule_channels_insert_guard
BEFORE INSERT ON channel_membership_rule_channels
FOR EACH ROW
BEGIN
    DECLARE parent_state VARCHAR(16) DEFAULT NULL;
    SELECT state INTO parent_state FROM channel_membership_rules
     WHERE id = NEW.channel_membership_rule_id FOR UPDATE;
    IF parent_state IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule parent does not exist.';
    END IF;
    IF parent_state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram membership-rule channels are immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER channel_membership_rule_channels_update_guard
BEFORE UPDATE ON channel_membership_rule_channels
FOR EACH ROW
BEGIN
    DECLARE parent_state VARCHAR(16) DEFAULT NULL;
    IF NOT (OLD.channel_membership_rule_id <=> NEW.channel_membership_rule_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule channel parent is immutable.';
    END IF;
    SELECT state INTO parent_state FROM channel_membership_rules
     WHERE id = OLD.channel_membership_rule_id FOR UPDATE;
    IF parent_state IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule parent does not exist.';
    END IF;
    IF parent_state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram membership-rule channels are immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER channel_membership_rule_channels_delete_guard
BEFORE DELETE ON channel_membership_rule_channels
FOR EACH ROW
BEGIN
    DECLARE parent_state VARCHAR(16) DEFAULT NULL;
    SELECT state INTO parent_state FROM channel_membership_rules
     WHERE id = OLD.channel_membership_rule_id FOR UPDATE;
    IF parent_state IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram membership-rule parent does not exist.';
    END IF;
    IF parent_state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram membership-rule channels are immutable.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_membership_rule_channels');
        Schema::dropIfExists('channel_membership_rules');
    }
};
