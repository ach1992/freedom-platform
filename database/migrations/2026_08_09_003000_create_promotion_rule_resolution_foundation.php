<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PRO-001 REF-001 BUY-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('rule_code', 128)->unique();
            $table->string('kind', 16);
            $table->dateTime('created_at', 6);
        });

        Schema::create('pricing_rule_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('pricing_rule_id')->constrained('pricing_rules')->restrictOnDelete();
            $table->string('mutation_key', 128)->unique();
            $table->char('mutation_payload_hash', 64);
            $table->unsignedBigInteger('version');
            $table->string('state', 16);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('discount_type', 16);
            $table->bigInteger('fixed_discount_irr')->nullable();
            $table->unsignedSmallInteger('percentage_basis_points')->nullable();
            $table->bigInteger('minimum_order_irr')->default(0);
            $table->bigInteger('maximum_discount_irr')->nullable();
            $table->dateTime('effective_from', 6)->nullable();
            $table->dateTime('effective_until', 6)->nullable();
            $table->unsignedBigInteger('total_use_limit')->nullable();
            $table->unsignedBigInteger('per_user_use_limit')->nullable();
            $table->boolean('first_purchase_only')->default(false);
            $table->string('audience', 16);
            $table->string('tier_code', 32)->nullable();
            $table->foreign('tier_code', 'pricing_rule_version_tier_fk')
                ->references('code')->on('customer_tiers')->restrictOnDelete();
            $table->foreignId('customer_tag_id')->nullable()->constrained('customer_tags')->restrictOnDelete();
            $table->foreignId('plan_offering_id')->nullable()->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('sales_server_id')->nullable()->constrained('sales_servers')->restrictOnDelete();
            $table->string('action', 32)->nullable();
            $table->string('referral_source_code', 128)->nullable();
            $table->boolean('allows_free_order')->default(false);
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['pricing_rule_id', 'version'], 'pricing_rule_version_unique');
            $table->index(['state', 'priority', 'pricing_rule_id'], 'pricing_rule_resolution_candidates_idx');
        });

        Schema::create('pricing_rule_resolutions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('resolution_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->string('action', 32);
            $table->bigInteger('input_price_irr');
            $table->unsignedBigInteger('observed_total_uses')->default(0);
            $table->unsignedBigInteger('observed_user_uses')->default(0);
            $table->boolean('had_prior_successful_purchase')->default(false);
            $table->string('referral_source_code', 128)->nullable();
            $table->foreignId('pricing_rule_id')->nullable()->constrained('pricing_rules')->restrictOnDelete();
            $table->foreignId('pricing_rule_version_id')->nullable()->constrained('pricing_rule_versions')->restrictOnDelete();
            $table->string('rule_code_snapshot', 128)->nullable();
            $table->string('rule_kind_snapshot', 16)->nullable();
            $table->unsignedBigInteger('rule_version')->nullable();
            $table->char('rule_configuration_hash', 64)->nullable();
            $table->bigInteger('discount_irr')->default(0);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at'], 'pricing_rule_resolution_user_idx');
            $table->index(['pricing_rule_version_id', 'created_at'], 'pricing_rule_resolution_version_idx');
        });

        $this->addChecks();
        $this->createGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_resolutions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_resolutions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_resolutions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_versions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rule_versions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rules_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS pricing_rules_update_guard');
        Schema::dropIfExists('pricing_rule_resolutions');
        Schema::dropIfExists('pricing_rule_versions');
        Schema::dropIfExists('pricing_rules');
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_kind_chk CHECK (`kind` IN ('promotion', 'referral'))");
        DB::statement("ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_state_chk CHECK (`state` IN ('draft', 'active', 'disabled', 'archived'))");
        DB::statement("ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_discount_type_chk CHECK (`discount_type` IN ('fixed', 'percentage'))");
        DB::statement("ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_discount_shape_chk CHECK ((`discount_type` = 'fixed' AND `fixed_discount_irr` IS NOT NULL AND `fixed_discount_irr` >= 1 AND `percentage_basis_points` IS NULL) OR (`discount_type` = 'percentage' AND `fixed_discount_irr` IS NULL AND `percentage_basis_points` BETWEEN 1 AND 10000))");
        DB::statement('ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_amounts_chk CHECK (`minimum_order_irr` >= 0 AND (`maximum_discount_irr` IS NULL OR `maximum_discount_irr` >= 1))');
        DB::statement('ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_effective_window_chk CHECK (`effective_from` IS NULL OR `effective_until` IS NULL OR `effective_until` > `effective_from`)');
        DB::statement('ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_limits_chk CHECK ((`total_use_limit` IS NULL OR `total_use_limit` >= 1) AND (`per_user_use_limit` IS NULL OR `per_user_use_limit` >= 1) AND (`total_use_limit` IS NULL OR `per_user_use_limit` IS NULL OR `per_user_use_limit` <= `total_use_limit`))');
        DB::statement("ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_audience_chk CHECK (`audience` IN ('customers', 'agents', 'both'))");
        DB::statement("ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_action_chk CHECK (`action` IS NULL OR `action` IN ('purchase', 'renew', 'add_data', 'add_days'))");
        DB::statement('ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_hashes_chk CHECK (`mutation_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE pricing_rule_versions ADD CONSTRAINT pricing_rule_versions_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE pricing_rule_resolutions ADD CONSTRAINT pricing_rule_resolutions_action_chk CHECK (`action` IN ('purchase', 'renew', 'add_data', 'add_days'))");
        DB::statement('ALTER TABLE pricing_rule_resolutions ADD CONSTRAINT pricing_rule_resolutions_amount_chk CHECK (`input_price_irr` >= 0 AND `discount_irr` >= 0 AND `discount_irr` <= `input_price_irr`)');
        DB::statement('ALTER TABLE pricing_rule_resolutions ADD CONSTRAINT pricing_rule_resolutions_usage_chk CHECK (`observed_user_uses` <= `observed_total_uses`)');
        DB::statement("ALTER TABLE pricing_rule_resolutions ADD CONSTRAINT pricing_rule_resolutions_rule_shape_chk CHECK ((`pricing_rule_id` IS NULL AND `pricing_rule_version_id` IS NULL AND `rule_code_snapshot` IS NULL AND `rule_kind_snapshot` IS NULL AND `rule_version` IS NULL AND `rule_configuration_hash` IS NULL AND `discount_irr` = 0) OR (`pricing_rule_id` IS NOT NULL AND `pricing_rule_version_id` IS NOT NULL AND `rule_code_snapshot` IS NOT NULL AND `rule_kind_snapshot` IN ('promotion', 'referral') AND `rule_version` >= 1 AND `rule_configuration_hash` REGEXP '^[0-9a-f]{64}$'))");
        DB::statement("ALTER TABLE pricing_rule_resolutions ADD CONSTRAINT pricing_rule_resolutions_hashes_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE pricing_rule_resolutions ADD CONSTRAINT pricing_rule_resolutions_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
    }

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rules_update_guard
BEFORE UPDATE ON pricing_rules FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule identity is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rules_delete_guard
BEFORE DELETE ON pricing_rules FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule identity cannot be deleted.';
END
SQL);

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

    SELECT COALESCE(MAX(version), 0) INTO latest_version
      FROM pricing_rule_versions WHERE pricing_rule_id = NEW.pricing_rule_id;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule version is not sequential.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule configuration hash mismatch.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rule_versions_update_guard
BEFORE UPDATE ON pricing_rule_versions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule version is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rule_versions_delete_guard
BEFORE DELETE ON pricing_rule_versions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule version cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rule_resolutions_insert_guard
BEFORE INSERT ON pricing_rule_resolutions FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM users u
        WHERE u.id = NEW.user_id
          AND u.account_status = 'active'
          AND u.account_type IN ('customer', 'agent')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule resolution subject is not an active customer or agent.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_snapshot_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule resolution snapshot hash mismatch.';
    END IF;

    IF NEW.discount_irr > 0 AND NOT EXISTS (
        SELECT 1 FROM plan_offerings o WHERE o.id = NEW.plan_offering_id AND o.discount_eligible = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule resolution offering is not discount eligible.';
    END IF;

    IF NEW.pricing_rule_version_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM pricing_rule_versions v
        INNER JOIN pricing_rules r ON r.id = v.pricing_rule_id
        WHERE v.id = NEW.pricing_rule_version_id
          AND r.id = NEW.pricing_rule_id
          AND r.rule_code = NEW.rule_code_snapshot
          AND r.kind = NEW.rule_kind_snapshot
          AND v.version = NEW.rule_version
          AND v.configuration_hash = NEW.rule_configuration_hash
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule resolution identity mismatch.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rule_resolutions_update_guard
BEFORE UPDATE ON pricing_rule_resolutions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule resolution is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER pricing_rule_resolutions_delete_guard
BEFORE DELETE ON pricing_rule_resolutions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pricing rule resolution cannot be deleted.';
END
SQL);
    }
};
