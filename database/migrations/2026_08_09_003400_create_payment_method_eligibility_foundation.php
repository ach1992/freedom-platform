<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-001 BUY-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('method_code', 64)->unique();
            $table->string('kind', 32);
            $table->string('provider_code', 64)->nullable();
            $table->dateTime('created_at', 6);
        });

        Schema::create('payment_method_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('mutation_key', 128)->unique();
            $table->char('mutation_payload_hash', 64);
            $table->unsignedBigInteger('version');
            $table->string('state', 16);
            $table->unsignedSmallInteger('display_priority')->default(0);
            $table->bigInteger('minimum_amount_irr')->nullable();
            $table->bigInteger('maximum_amount_irr')->nullable();
            $table->boolean('allow_degraded_health')->default(false);
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['payment_method_id', 'version'], 'payment_method_version_identity_uq');
            $table->index(['payment_method_id', 'state', 'version'], 'payment_method_version_state_idx');
        });

        Schema::create('payment_eligibility_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('rule_code', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['payment_method_id', 'rule_code'], 'payment_eligibility_rule_code_uq');
        });

        Schema::create('payment_eligibility_rule_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_eligibility_rule_id')->constrained('payment_eligibility_rules', 'id', 'pay_elig_rule_ver_rule_fk')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('mutation_key', 128)->unique();
            $table->char('mutation_payload_hash', 64);
            $table->unsignedBigInteger('version');
            $table->string('state', 16);
            $table->string('effect', 16);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_override')->default(false);
            $table->string('account_type', 32)->nullable();
            $table->string('tier_code', 32)->nullable();
            $table->string('identity_status', 32)->nullable();
            $table->foreignId('customer_tag_id')->nullable()->constrained('customer_tags')->restrictOnDelete();
            $table->bigInteger('minimum_amount_irr')->nullable();
            $table->bigInteger('maximum_amount_irr')->nullable();
            $table->string('action', 32)->nullable();
            $table->foreignId('plan_offering_id')->nullable()->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('sales_server_id')->nullable()->constrained('sales_servers')->restrictOnDelete();
            $table->dateTime('effective_from', 6)->nullable();
            $table->dateTime('effective_until', 6)->nullable();
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['payment_eligibility_rule_id', 'version'], 'payment_eligibility_rule_version_identity_uq');
            $table->index(['payment_method_id', 'state', 'priority'], 'payment_eligibility_rule_resolution_idx');
        });

        Schema::create('payment_eligibility_decisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('decision_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->restrictOnDelete();
            $table->ulid('quote_public_id_snapshot');
            $table->string('action', 32);
            $table->bigInteger('amount_irr');
            $table->char('currency', 3)->default('IRR');
            $table->string('account_type_snapshot', 32);
            $table->string('account_status_snapshot', 32);
            $table->string('tier_code_snapshot', 32)->nullable();
            $table->string('identity_status_snapshot', 32)->nullable();
            $table->json('subject_tag_ids_snapshot');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('product_id_snapshot')->constrained('products')->restrictOnDelete();
            $table->foreignId('sales_server_id_snapshot')->constrained('sales_servers')->restrictOnDelete();
            $table->unsignedSmallInteger('eligible_count')->default(0);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at'], 'payment_eligibility_user_created_idx');
            $table->index(['quote_id', 'created_at'], 'payment_eligibility_quote_created_idx');
        });

        Schema::create('payment_eligibility_decision_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('decision_id')->constrained('payment_eligibility_decisions')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('payment_method_version_id')->constrained('payment_method_versions', 'id', 'pay_elig_item_method_ver_fk')->restrictOnDelete();
            $table->ulid('method_public_id_snapshot');
            $table->string('method_code_snapshot', 64);
            $table->string('kind_snapshot', 32);
            $table->string('provider_code_snapshot', 64)->nullable();
            $table->unsignedBigInteger('method_version');
            $table->char('method_configuration_hash', 64);
            $table->unsignedSmallInteger('display_priority');
            $table->string('health_snapshot', 32);
            $table->string('outcome', 40);
            $table->boolean('eligible');
            $table->foreignId('payment_eligibility_rule_id')->nullable()->constrained('payment_eligibility_rules', 'id', 'pay_elig_item_rule_fk')->restrictOnDelete();
            $table->foreignId('payment_eligibility_rule_version_id')->nullable()->constrained('payment_eligibility_rule_versions', 'id', 'pay_elig_item_rule_ver_fk')->restrictOnDelete();
            $table->ulid('rule_public_id_snapshot')->nullable();
            $table->string('rule_code_snapshot', 64)->nullable();
            $table->unsignedBigInteger('rule_version')->nullable();
            $table->char('rule_configuration_hash', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['decision_id', 'payment_method_id'], 'payment_eligibility_decision_method_uq');
            $table->index(['payment_method_id', 'eligible', 'created_at'], 'payment_eligibility_method_result_idx');
        });

        $this->addChecks();
        $this->createInsertGuards();
        $this->createImmutableGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_decision_items_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_decision_items_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_decisions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_decisions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_rule_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_rule_versions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_rules_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_rules_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_method_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_method_versions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_methods_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_methods_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_decision_items_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_decisions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_eligibility_rule_versions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_method_versions_insert_guard');

        Schema::dropIfExists('payment_eligibility_decision_items');
        Schema::dropIfExists('payment_eligibility_decisions');
        Schema::dropIfExists('payment_eligibility_rule_versions');
        Schema::dropIfExists('payment_eligibility_rules');
        Schema::dropIfExists('payment_method_versions');
        Schema::dropIfExists('payment_methods');
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_kind_chk CHECK (`kind` IN ('wallet','card_to_card','gift_card','crypto','gateway'))");
        DB::statement("ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_versions_state_chk CHECK (`state` IN ('active','disabled','archived'))");
        DB::statement('ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_versions_amount_chk CHECK ((`minimum_amount_irr` IS NULL OR `minimum_amount_irr` >= 0) AND (`maximum_amount_irr` IS NULL OR `maximum_amount_irr` >= 0) AND (`minimum_amount_irr` IS NULL OR `maximum_amount_irr` IS NULL OR `maximum_amount_irr` >= `minimum_amount_irr`))');
        DB::statement('ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_versions_hash_chk CHECK (CHAR_LENGTH(`mutation_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_hash`) = 64)');
        DB::statement("ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_versions_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_state_chk CHECK (`state` IN ('active','disabled','archived'))");
        DB::statement("ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_effect_chk CHECK (`effect` IN ('allow','deny'))");
        DB::statement("ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_account_chk CHECK (`account_type` IS NULL OR `account_type` IN ('customer','agent'))");
        DB::statement("ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_action_chk CHECK (`action` IS NULL OR `action` IN ('purchase','renew','add_data','add_days'))");
        DB::statement('ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_amount_chk CHECK ((`minimum_amount_irr` IS NULL OR `minimum_amount_irr` >= 0) AND (`maximum_amount_irr` IS NULL OR `maximum_amount_irr` >= 0) AND (`minimum_amount_irr` IS NULL OR `maximum_amount_irr` IS NULL OR `maximum_amount_irr` >= `minimum_amount_irr`))');
        DB::statement('ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_window_chk CHECK (`effective_from` IS NULL OR `effective_until` IS NULL OR `effective_until` > `effective_from`)');
        DB::statement('ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_hash_chk CHECK (CHAR_LENGTH(`mutation_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_hash`) = 64)');
        DB::statement("ALTER TABLE payment_eligibility_rule_versions ADD CONSTRAINT payment_eligibility_rule_versions_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 40 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE payment_eligibility_decisions ADD CONSTRAINT payment_eligibility_decisions_action_chk CHECK (`action` IN ('purchase','renew','add_data','add_days'))");
        DB::statement("ALTER TABLE payment_eligibility_decisions ADD CONSTRAINT payment_eligibility_decisions_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement("ALTER TABLE payment_eligibility_decisions ADD CONSTRAINT payment_eligibility_decisions_account_chk CHECK (`account_type_snapshot` IN ('customer','agent') AND `account_status_snapshot` = 'active')");
        DB::statement('ALTER TABLE payment_eligibility_decisions ADD CONSTRAINT payment_eligibility_decisions_amount_chk CHECK (`amount_irr` >= 0)');
        DB::statement('ALTER TABLE payment_eligibility_decisions ADD CONSTRAINT payment_eligibility_decisions_hash_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE payment_eligibility_decisions ADD CONSTRAINT payment_eligibility_decisions_tags_chk CHECK (JSON_VALID(`subject_tag_ids_snapshot`) AND JSON_TYPE(`subject_tag_ids_snapshot`) = 'ARRAY' AND JSON_LENGTH(`subject_tag_ids_snapshot`) <= 128 AND OCTET_LENGTH(`subject_tag_ids_snapshot`) <= 4096)");
        DB::statement("ALTER TABLE payment_eligibility_decisions ADD CONSTRAINT payment_eligibility_decisions_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 16384)");

        DB::statement("ALTER TABLE payment_eligibility_decision_items ADD CONSTRAINT payment_eligibility_decision_items_kind_chk CHECK (`kind_snapshot` IN ('wallet','card_to_card','gift_card','crypto','gateway'))");
        DB::statement("ALTER TABLE payment_eligibility_decision_items ADD CONSTRAINT payment_eligibility_decision_items_health_chk CHECK (`health_snapshot` IN ('healthy','degraded','unavailable','misconfigured','missing'))");
        DB::statement("ALTER TABLE payment_eligibility_decision_items ADD CONSTRAINT payment_eligibility_decision_items_outcome_chk CHECK (`outcome` IN ('eligible','method_disabled','health_unavailable','amount_outside_method_limit','no_matching_rule','rule_denied'))");
        DB::statement('ALTER TABLE payment_eligibility_decision_items ADD CONSTRAINT payment_eligibility_decision_items_hash_chk CHECK (CHAR_LENGTH(`method_configuration_hash`) = 64 AND (`rule_configuration_hash` IS NULL OR CHAR_LENGTH(`rule_configuration_hash`) = 64))');
        DB::statement('ALTER TABLE payment_eligibility_decision_items ADD CONSTRAINT payment_eligibility_decision_items_rule_shape_chk CHECK ((`payment_eligibility_rule_id` IS NULL AND `payment_eligibility_rule_version_id` IS NULL AND `rule_public_id_snapshot` IS NULL AND `rule_code_snapshot` IS NULL AND `rule_version` IS NULL AND `rule_configuration_hash` IS NULL) OR (`payment_eligibility_rule_id` IS NOT NULL AND `payment_eligibility_rule_version_id` IS NOT NULL AND `rule_public_id_snapshot` IS NOT NULL AND `rule_code_snapshot` IS NOT NULL AND `rule_version` IS NOT NULL AND `rule_configuration_hash` IS NOT NULL))');
        DB::statement("ALTER TABLE payment_eligibility_decision_items ADD CONSTRAINT payment_eligibility_decision_items_eligible_shape_chk CHECK ((`eligible` = 1 AND `outcome` = 'eligible' AND `payment_eligibility_rule_id` IS NOT NULL) OR (`eligible` = 0 AND `outcome` <> 'eligible'))");
    }

    private function createInsertGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_versions_insert_guard
BEFORE INSERT ON payment_method_versions
FOR EACH ROW
BEGIN
    DECLARE stable_count INT DEFAULT 0;

    SELECT COUNT(*) INTO stable_count
    FROM payment_methods m
    WHERE m.id = NEW.payment_method_id;

    IF stable_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method version requires a stable payment method.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method configuration hash mismatch.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_rule_versions_insert_guard
BEFORE INSERT ON payment_eligibility_rule_versions
FOR EACH ROW
BEGIN
    DECLARE stable_count INT DEFAULT 0;
    DECLARE offering_count INT DEFAULT 0;

    SELECT COUNT(*) INTO stable_count
    FROM payment_eligibility_rules r
    WHERE r.id = NEW.payment_eligibility_rule_id
      AND r.payment_method_id = NEW.payment_method_id;

    IF stable_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility rule version stable identity mismatch.';
    END IF;

    IF NEW.plan_offering_id IS NOT NULL THEN
        SELECT COUNT(*) INTO offering_count
        FROM plan_offerings o
        WHERE o.id = NEW.plan_offering_id
          AND (NEW.product_id IS NULL OR o.product_id = NEW.product_id)
          AND (NEW.sales_server_id IS NULL OR o.sales_server_id = NEW.sales_server_id);
        IF offering_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility compound offering scope mismatch.';
        END IF;
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility rule configuration hash mismatch.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_decisions_insert_guard
BEFORE INSERT ON payment_eligibility_decisions
FOR EACH ROW
BEGIN
    DECLARE authority_count INT DEFAULT 0;

    SELECT COUNT(*) INTO authority_count
    FROM quotes q
    INNER JOIN users u ON u.id = q.user_id
    LEFT JOIN customer_profiles p ON p.user_id = u.id
    INNER JOIN plan_offerings o ON o.id = q.plan_offering_id
    WHERE q.id = NEW.quote_id
      AND q.public_id = NEW.quote_public_id_snapshot
      AND q.user_id = NEW.user_id
      AND q.final_price_irr = NEW.amount_irr
      AND q.currency = NEW.currency
      AND q.account_type_snapshot = NEW.account_type_snapshot
      AND q.plan_offering_id = NEW.plan_offering_id
      AND q.valid_from <= NOW(6)
      AND q.expires_at > NOW(6)
      AND u.account_status = 'active'
      AND u.account_type = NEW.account_type_snapshot
      AND NEW.account_status_snapshot = 'active'
      AND (p.identity_verification_status <=> NEW.identity_status_snapshot)
      AND o.product_id = NEW.product_id_snapshot
      AND o.sales_server_id = NEW.sales_server_id_snapshot;

    IF authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision authority mismatch.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision snapshot hash mismatch.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_decision_items_insert_guard
BEFORE INSERT ON payment_eligibility_decision_items
FOR EACH ROW
BEGIN
    DECLARE method_count INT DEFAULT 0;
    DECLARE rule_count INT DEFAULT 0;
    DECLARE eligible_rule_count INT DEFAULT 0;

    SELECT COUNT(*) INTO method_count
    FROM payment_method_versions v
    INNER JOIN payment_methods m ON m.id = v.payment_method_id
    WHERE v.id = NEW.payment_method_version_id
      AND v.payment_method_id = NEW.payment_method_id
      AND m.public_id = NEW.method_public_id_snapshot
      AND m.method_code = NEW.method_code_snapshot
      AND m.kind = NEW.kind_snapshot
      AND (m.provider_code <=> NEW.provider_code_snapshot)
      AND v.version = NEW.method_version
      AND v.configuration_hash = NEW.method_configuration_hash
      AND v.display_priority = NEW.display_priority;

    IF method_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision item method identity mismatch.';
    END IF;

    IF NEW.payment_eligibility_rule_id IS NOT NULL THEN
        SELECT COUNT(*) INTO rule_count
        FROM payment_eligibility_rule_versions v
        INNER JOIN payment_eligibility_rules r ON r.id = v.payment_eligibility_rule_id
        WHERE v.id = NEW.payment_eligibility_rule_version_id
          AND v.payment_eligibility_rule_id = NEW.payment_eligibility_rule_id
          AND v.payment_method_id = NEW.payment_method_id
          AND r.payment_method_id = NEW.payment_method_id
          AND r.public_id = NEW.rule_public_id_snapshot
          AND r.rule_code = NEW.rule_code_snapshot
          AND v.version = NEW.rule_version
          AND v.configuration_hash = NEW.rule_configuration_hash;
        IF rule_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision item rule identity mismatch.';
        END IF;
    END IF;

    IF NEW.eligible = 1 THEN
        SELECT COUNT(*) INTO eligible_rule_count
        FROM payment_method_versions mv
        INNER JOIN payment_eligibility_rule_versions rv ON rv.id = NEW.payment_eligibility_rule_version_id
        WHERE mv.id = NEW.payment_method_version_id
          AND mv.state = 'active'
          AND (NEW.health_snapshot = 'healthy' OR (NEW.health_snapshot = 'degraded' AND mv.allow_degraded_health = 1))
          AND rv.state = 'active'
          AND rv.effect = 'allow';
        IF eligible_rule_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility eligible item is not backed by active allow authority.';
        END IF;
    END IF;
END
SQL);
    }

    private function createImmutableGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_methods_update_guard BEFORE UPDATE ON payment_methods FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_methods_delete_guard BEFORE DELETE ON payment_methods FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are non-deletable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_versions_update_guard BEFORE UPDATE ON payment_method_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_versions_delete_guard BEFORE DELETE ON payment_method_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are non-deletable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_rules_update_guard BEFORE UPDATE ON payment_eligibility_rules FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_rules_delete_guard BEFORE DELETE ON payment_eligibility_rules FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are non-deletable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_rule_versions_update_guard BEFORE UPDATE ON payment_eligibility_rule_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_rule_versions_delete_guard BEFORE DELETE ON payment_eligibility_rule_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are non-deletable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_decisions_update_guard BEFORE UPDATE ON payment_eligibility_decisions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_decisions_delete_guard BEFORE DELETE ON payment_eligibility_decisions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are non-deletable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_decision_items_update_guard BEFORE UPDATE ON payment_eligibility_decision_items FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_eligibility_decision_items_delete_guard BEFORE DELETE ON payment_eligibility_decision_items FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAY-001 records are non-deletable.'; END
SQL);
    }
};
