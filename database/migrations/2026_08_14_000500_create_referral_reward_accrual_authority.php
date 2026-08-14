<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        Schema::create('referral_reward_accruals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('purchase_settlement_id')->unique()->constrained('purchase_settlements')->restrictOnDelete();
            $table->foreignId('referral_relationship_id')->constrained('referral_relationships')->restrictOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pricing_rule_id')->constrained('pricing_rules')->restrictOnDelete();
            $table->foreignId('pricing_rule_version_id')->constrained('pricing_rule_versions')->restrictOnDelete();
            $table->foreignId('source_quote_id')->constrained('quotes')->restrictOnDelete();
            $table->char('purchase_settlement_public_id', 26);
            $table->char('source_quote_public_id', 26);
            $table->string('rule_code_snapshot', 128);
            $table->unsignedBigInteger('rule_version');
            $table->char('rule_configuration_hash', 64);
            $table->bigInteger('qualifying_amount_irr');
            $table->bigInteger('reward_amount_irr');
            $table->string('recipient_policy', 16);
            $table->dateTime('release_at', 6);
            $table->dateTime('expires_at', 6)->nullable();
            $table->boolean('transferable')->default(false);
            $table->dateTime('created_at', 6);
            $table->index(['pricing_rule_version_id', 'created_at'], 'referral_reward_accrual_rule_idx');
            $table->index(['referred_user_id', 'created_at'], 'referral_reward_accrual_referred_idx');
        });

        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('accrual_id')->constrained('referral_reward_accruals')->restrictOnDelete();
            $table->foreignId('purchase_settlement_id')->constrained('purchase_settlements')->restrictOnDelete();
            $table->string('recipient_role', 16);
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_irr');
            $table->string('state', 32)->default('pending');
            $table->dateTime('release_at', 6);
            $table->dateTime('expires_at', 6)->nullable();
            $table->boolean('transferable')->default(false);
            $table->dateTime('created_at', 6);
            $table->unique(['accrual_id', 'recipient_role'], 'referral_reward_accrual_role_unique');
            $table->unique(['purchase_settlement_id', 'recipient_role'], 'referral_reward_settlement_role_unique');
            $table->index(['recipient_user_id', 'state', 'release_at'], 'referral_reward_recipient_state_idx');
        });

        DB::statement('ALTER TABLE referral_reward_accruals ADD CONSTRAINT referral_reward_accrual_amount_chk CHECK (`qualifying_amount_irr` > 0 AND `reward_amount_irr` > 0 AND `reward_amount_irr` <= `qualifying_amount_irr`)');
        DB::statement("ALTER TABLE referral_reward_accruals ADD CONSTRAINT referral_reward_accrual_recipient_chk CHECK (`recipient_policy` IN ('inviter','referred','both'))");
        DB::statement('ALTER TABLE referral_reward_accruals ADD CONSTRAINT referral_reward_accrual_expiry_chk CHECK (`expires_at` IS NULL OR `expires_at` > `release_at`)');
        DB::statement("ALTER TABLE referral_reward_accruals ADD CONSTRAINT referral_reward_accrual_hash_chk CHECK (`rule_configuration_hash` REGEXP '^[0-9a-f]{64}$')");

        DB::statement("ALTER TABLE referral_rewards ADD CONSTRAINT referral_reward_role_chk CHECK (`recipient_role` IN ('inviter','referred'))");
        DB::statement('ALTER TABLE referral_rewards ADD CONSTRAINT referral_reward_amount_chk CHECK (`amount_irr` > 0)');
        DB::statement("ALTER TABLE referral_rewards ADD CONSTRAINT referral_reward_state_chk CHECK (`state` = 'pending')");
        DB::statement('ALTER TABLE referral_rewards ADD CONSTRAINT referral_reward_expiry_chk CHECK (`expires_at` IS NULL OR `expires_at` > `release_at`)');

        $this->createAccrualGuards();
        $this->createRewardGuards();
        $this->replacePricingRuleVersionInsertGuard();
    }

    public function down(): void
    {
        if (DB::table('referral_rewards')->exists() || DB::table('referral_reward_accruals')->exists()) {
            throw new RuntimeException('Cannot roll back referral reward authority while reward state exists.');
        }
        if (DB::table('pricing_rule_versions')
            ->whereRaw("JSON_CONTAINS_PATH(configuration_snapshot, 'one', '$.referral_reward_recipient') = 1")
            ->exists()) {
            throw new RuntimeException('Cannot roll back referral reward policy support while reward-enabled rule versions exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS referral_rewards_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_rewards_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_rewards_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_accruals_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_accruals_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_reward_accruals_insert_guard');
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referral_reward_accruals');
        $this->restorePricingRuleVersionInsertGuard();
    }

    private function createAccrualGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_accruals_insert_guard
BEFORE INSERT ON referral_reward_accruals
FOR EACH ROW
BEGIN
    DECLARE settlement_count INT DEFAULT 0;
    DECLARE relationship_count INT DEFAULT 0;
    DECLARE rule_count INT DEFAULT 0;
    DECLARE shared_phone_count INT DEFAULT 0;
    DECLARE total_accrual_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE referred_accrual_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE relationship_accrual_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE rule_lock_id BIGINT UNSIGNED;
    DECLARE rule_discount_type VARCHAR(16);
    DECLARE rule_fixed BIGINT;
    DECLARE rule_bps INT UNSIGNED;
    DECLARE rule_max BIGINT;
    DECLARE rule_min BIGINT;
    DECLARE rule_total_limit BIGINT UNSIGNED;
    DECLARE rule_user_limit BIGINT UNSIGNED;
    DECLARE rule_first_purchase BOOLEAN;
    DECLARE rule_plan_offering BIGINT UNSIGNED;
    DECLARE rule_product BIGINT UNSIGNED;
    DECLARE rule_sales_server BIGINT UNSIGNED;
    DECLARE rule_action VARCHAR(32);
    DECLARE rule_source VARCHAR(128);
    DECLARE rule_snapshot JSON;
    DECLARE policy_recipient VARCHAR(16);
    DECLARE policy_pending BIGINT UNSIGNED;
    DECLARE policy_expiry BIGINT UNSIGNED;
    DECLARE policy_transferable VARCHAR(8);
    DECLARE policy_referral_limit BIGINT UNSIGNED;
    DECLARE expected_reward BIGINT;
    DECLARE expected_release DATETIME(6);
    DECLARE expected_expiry DATETIME(6);
    DECLARE offering_product BIGINT UNSIGNED;
    DECLARE offering_server BIGINT UNSIGNED;
    DECLARE inviter_token VARCHAR(32);
    DECLARE locked_settlement BIGINT UNSIGNED;

    SELECT id INTO rule_lock_id
    FROM pricing_rule_versions
    WHERE id = NEW.pricing_rule_version_id
    FOR UPDATE;
    IF rule_lock_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward rule version does not exist.';
    END IF;

    SELECT COUNT(*) INTO settlement_count
    FROM purchase_settlements s
    INNER JOIN quotes q ON q.id = s.source_quote_id
    WHERE s.id = NEW.purchase_settlement_id
      AND s.public_id = NEW.purchase_settlement_public_id
      AND s.user_id = NEW.referred_user_id
      AND s.source_quote_id = NEW.source_quote_id
      AND s.source_quote_public_id = NEW.source_quote_public_id
      AND s.amount_irr = NEW.qualifying_amount_irr
      AND s.currency = 'IRR'
      AND q.id = NEW.source_quote_id
      AND q.public_id = NEW.source_quote_public_id
      AND q.user_id = NEW.referred_user_id
      AND q.final_price_irr = NEW.qualifying_amount_irr
      AND q.currency = 'IRR';
    IF settlement_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward requires one matching authoritative purchase settlement and quote.';
    END IF;

    SELECT COUNT(*), MAX(r.locked_purchase_settlement_id), MAX(i.token)
      INTO relationship_count, locked_settlement, inviter_token
    FROM referral_relationships r
    INNER JOIN referral_identities i ON i.id = r.inviter_referral_identity_id AND i.user_id = r.inviter_user_id
    WHERE r.id = NEW.referral_relationship_id
      AND r.referred_user_id = NEW.referred_user_id
      AND r.inviter_user_id = NEW.inviter_user_id
      AND r.locked_purchase_settlement_id IS NOT NULL;
    IF relationship_count <> 1 OR NEW.referred_user_id = NEW.inviter_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward relationship is invalid.';
    END IF;

    SELECT COUNT(*) INTO shared_phone_count
    FROM phone_numbers referred_phone
    INNER JOIN phone_numbers inviter_phone
      ON inviter_phone.lookup_hash = referred_phone.lookup_hash
     AND inviter_phone.user_id = NEW.inviter_user_id
     AND inviter_phone.verified_at IS NOT NULL
    WHERE referred_phone.user_id = NEW.referred_user_id
      AND referred_phone.verified_at IS NOT NULL;
    IF shared_phone_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Shared verified phone cannot receive referral reward.';
    END IF;

    SELECT COUNT(*), MAX(v.discount_type), MAX(v.fixed_discount_irr), MAX(v.percentage_basis_points),
           MAX(v.maximum_discount_irr), MAX(v.minimum_order_irr), MAX(v.total_use_limit), MAX(v.per_user_use_limit),
           MAX(v.first_purchase_only), MAX(v.plan_offering_id), MAX(v.product_id), MAX(v.sales_server_id),
           MAX(v.action), MAX(v.referral_source_code), MAX(v.configuration_snapshot)
      INTO rule_count, rule_discount_type, rule_fixed, rule_bps, rule_max, rule_min, rule_total_limit, rule_user_limit,
           rule_first_purchase, rule_plan_offering, rule_product, rule_sales_server, rule_action, rule_source, rule_snapshot
    FROM pricing_rule_versions v
    INNER JOIN pricing_rules r ON r.id = v.pricing_rule_id
    WHERE v.id = NEW.pricing_rule_version_id
      AND r.id = NEW.pricing_rule_id
      AND r.kind = 'referral'
      AND r.rule_code = NEW.rule_code_snapshot
      AND v.version = NEW.rule_version
      AND v.configuration_hash = NEW.rule_configuration_hash
      AND v.state = 'active'
      AND v.created_at <= (SELECT settled_at FROM purchase_settlements WHERE id = NEW.purchase_settlement_id)
      AND (v.effective_from IS NULL OR v.effective_from <= (SELECT settled_at FROM purchase_settlements WHERE id = NEW.purchase_settlement_id))
      AND (v.effective_until IS NULL OR v.effective_until > (SELECT settled_at FROM purchase_settlements WHERE id = NEW.purchase_settlement_id));
    IF rule_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward rule version is not authoritative for settlement time.';
    END IF;

    IF LOWER(SHA2(CAST(rule_snapshot AS CHAR), 256)) <> NEW.rule_configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward rule snapshot hash mismatch.';
    END IF;

    SET policy_recipient = JSON_UNQUOTE(JSON_EXTRACT(rule_snapshot, '$.referral_reward_recipient'));
    SET policy_pending = CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(rule_snapshot, '$.referral_pending_hours')), '24') AS UNSIGNED);
    SET policy_expiry = CAST(JSON_UNQUOTE(JSON_EXTRACT(rule_snapshot, '$.referral_expiry_hours')) AS UNSIGNED);
    SET policy_transferable = JSON_UNQUOTE(JSON_EXTRACT(rule_snapshot, '$.referral_transferable'));
    SET policy_referral_limit = CAST(JSON_UNQUOTE(JSON_EXTRACT(rule_snapshot, '$.per_referral_use_limit')) AS UNSIGNED);
    IF policy_recipient NOT IN ('inviter','referred','both') OR policy_pending < 1 OR policy_transferable NOT IN ('true','false') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward policy snapshot is invalid.';
    END IF;
    IF NEW.recipient_policy <> policy_recipient OR NEW.transferable <> (policy_transferable = 'true') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward policy identity mismatch.';
    END IF;

    SELECT o.product_id, o.sales_server_id INTO offering_product, offering_server
    FROM quotes q
    INNER JOIN plan_offerings o ON o.id = q.plan_offering_id
    WHERE q.id = NEW.source_quote_id;
    IF rule_plan_offering IS NOT NULL AND rule_plan_offering <> (SELECT plan_offering_id FROM quotes WHERE id = NEW.source_quote_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward offering scope mismatch.';
    END IF;
    IF rule_product IS NOT NULL AND rule_product <> offering_product THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward product scope mismatch.';
    END IF;
    IF rule_sales_server IS NOT NULL AND rule_sales_server <> offering_server THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward sales server scope mismatch.';
    END IF;
    IF rule_action IS NOT NULL AND rule_action <> 'purchase' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward action scope mismatch.';
    END IF;
    IF rule_source IS NULL OR inviter_token IS NULL OR rule_source <> inviter_token THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward source identity mismatch.';
    END IF;
    IF NEW.qualifying_amount_irr < rule_min THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward minimum order is not satisfied.';
    END IF;
    IF rule_first_purchase = 1 AND locked_settlement <> NEW.purchase_settlement_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward is limited to first successful purchase.';
    END IF;

    IF rule_discount_type = 'fixed' THEN
        SET expected_reward = rule_fixed;
    ELSEIF rule_discount_type = 'percentage' THEN
        SET expected_reward = (NEW.qualifying_amount_irr DIV 10000) * rule_bps
            + ((NEW.qualifying_amount_irr MOD 10000) * rule_bps) DIV 10000;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward calculation type is invalid.';
    END IF;
    IF rule_max IS NOT NULL THEN
        SET expected_reward = LEAST(expected_reward, rule_max);
    END IF;
    IF expected_reward < 1 OR expected_reward > NEW.qualifying_amount_irr OR NEW.reward_amount_irr <> expected_reward THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward amount is invalid.';
    END IF;

    SET expected_release = DATE_ADD((SELECT settled_at FROM purchase_settlements WHERE id = NEW.purchase_settlement_id), INTERVAL policy_pending HOUR);
    SET expected_expiry = IF(policy_expiry IS NULL, NULL, DATE_ADD(expected_release, INTERVAL policy_expiry HOUR));
    IF NEW.release_at <> expected_release OR NOT (NEW.expires_at <=> expected_expiry) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward timing is invalid.';
    END IF;

    SELECT COUNT(*) INTO total_accrual_count FROM referral_reward_accruals WHERE pricing_rule_version_id = NEW.pricing_rule_version_id;
    SELECT COUNT(*) INTO referred_accrual_count FROM referral_reward_accruals WHERE pricing_rule_version_id = NEW.pricing_rule_version_id AND referred_user_id = NEW.referred_user_id;
    SELECT COUNT(*) INTO relationship_accrual_count FROM referral_reward_accruals WHERE pricing_rule_version_id = NEW.pricing_rule_version_id AND referral_relationship_id = NEW.referral_relationship_id;
    IF rule_total_limit IS NOT NULL AND total_accrual_count >= rule_total_limit THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward total limit is exhausted.';
    END IF;
    IF rule_user_limit IS NOT NULL AND referred_accrual_count >= rule_user_limit THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward per-user limit is exhausted.';
    END IF;
    IF policy_referral_limit IS NOT NULL AND relationship_accrual_count >= policy_referral_limit THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward per-referral limit is exhausted.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_accruals_update_guard
BEFORE UPDATE ON referral_reward_accruals FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward accruals are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_reward_accruals_delete_guard
BEFORE DELETE ON referral_reward_accruals FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward accruals are non-deletable.';
END
SQL);
    }

    private function createRewardGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_rewards_insert_guard
BEFORE INSERT ON referral_rewards
FOR EACH ROW
BEGIN
    DECLARE valid_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_count
    FROM referral_reward_accruals a
    WHERE a.id = NEW.accrual_id
      AND a.purchase_settlement_id = NEW.purchase_settlement_id
      AND a.reward_amount_irr = NEW.amount_irr
      AND a.release_at = NEW.release_at
      AND (a.expires_at <=> NEW.expires_at)
      AND a.transferable = NEW.transferable
      AND ((NEW.recipient_role = 'inviter' AND a.recipient_policy IN ('inviter','both') AND NEW.recipient_user_id = a.inviter_user_id)
        OR (NEW.recipient_role = 'referred' AND a.recipient_policy IN ('referred','both') AND NEW.recipient_user_id = a.referred_user_id));
    IF valid_count <> 1 OR NEW.state <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward recipient state does not match accrual authority.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_rewards_update_guard
BEFORE UPDATE ON referral_rewards FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pending referral rewards are immutable until lifecycle authority is installed.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_rewards_delete_guard
BEFORE DELETE ON referral_rewards FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral rewards are non-deletable.';
END
SQL);
    }

    private function replacePricingRuleVersionInsertGuard(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_versions_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rule_versions_insert_guard
BEFORE INSERT ON pricing_rule_versions FOR EACH ROW
BEGIN
    DECLARE parent_kind VARCHAR(16);
    DECLARE latest_version BIGINT UNSIGNED;
    DECLARE reward_recipient VARCHAR(16);
    DECLARE pending_hours BIGINT UNSIGNED;
    DECLARE expiry_hours BIGINT UNSIGNED;
    DECLARE referral_limit BIGINT UNSIGNED;

    SELECT kind INTO parent_kind FROM pricing_rules WHERE id = NEW.pricing_rule_id;
    IF parent_kind IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule parent does not exist.';
    END IF;
    IF (parent_kind = 'referral' AND NEW.referral_source_code IS NULL)
       OR (parent_kind = 'promotion' AND NEW.referral_source_code IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule referral source shape is invalid.';
    END IF;

    SELECT COALESCE(MAX(version), 0) INTO latest_version FROM pricing_rule_versions WHERE pricing_rule_id = NEW.pricing_rule_id;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule configuration hash mismatch.';
    END IF;

    IF JSON_CONTAINS_PATH(NEW.configuration_snapshot, 'one', '$.referral_reward_recipient') = 1 THEN
        IF parent_kind <> 'referral'
           OR JSON_CONTAINS_PATH(NEW.configuration_snapshot, 'one', '$.referral_transferable') <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward policy requires referral rule identity.';
        END IF;
        SET reward_recipient = JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.referral_reward_recipient'));
        SET pending_hours = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.referral_pending_hours')) AS UNSIGNED);
        SET expiry_hours = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.referral_expiry_hours')) AS UNSIGNED);
        SET referral_limit = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.per_referral_use_limit')) AS UNSIGNED);
        IF reward_recipient NOT IN ('inviter','referred','both')
           OR JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.referral_transferable')) <> 'BOOLEAN'
           OR (JSON_EXTRACT(NEW.configuration_snapshot, '$.referral_pending_hours') IS NOT NULL AND pending_hours < 1)
           OR (JSON_EXTRACT(NEW.configuration_snapshot, '$.referral_expiry_hours') IS NOT NULL AND expiry_hours < 1)
           OR (JSON_EXTRACT(NEW.configuration_snapshot, '$.per_referral_use_limit') IS NOT NULL AND referral_limit < 1)
           OR (NEW.total_use_limit IS NOT NULL AND referral_limit IS NOT NULL AND referral_limit > NEW.total_use_limit) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward policy is invalid.';
        END IF;
    ELSEIF JSON_CONTAINS_PATH(NEW.configuration_snapshot, 'one', '$.referral_pending_hours', '$.referral_expiry_hours', '$.referral_transferable', '$.per_referral_use_limit') = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral reward policy is incomplete.';
    END IF;
END
SQL);
    }

    private function restorePricingRuleVersionInsertGuard(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_versions_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rule_versions_insert_guard
BEFORE INSERT ON pricing_rule_versions FOR EACH ROW
BEGIN
    DECLARE parent_kind VARCHAR(16);
    DECLARE latest_version BIGINT UNSIGNED;

    SELECT kind INTO parent_kind FROM pricing_rules WHERE id = NEW.pricing_rule_id;
    IF parent_kind IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule parent does not exist.';
    END IF;
    IF (parent_kind = 'referral' AND NEW.referral_source_code IS NULL)
       OR (parent_kind = 'promotion' AND NEW.referral_source_code IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule referral source shape is invalid.';
    END IF;

    SELECT COALESCE(MAX(version), 0) INTO latest_version FROM pricing_rule_versions WHERE pricing_rule_id = NEW.pricing_rule_id;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule version is not sequential.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule configuration hash mismatch.';
    END IF;
END
SQL);
    }
};
