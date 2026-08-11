<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-005 ACL-002 SEC-002 DAT-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('custom_plan_policies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->unique()->constrained('plan_offerings')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('minimum_data_gb');
            $table->unsignedInteger('maximum_data_gb');
            $table->unsignedInteger('data_step_gb');
            $table->unsignedInteger('minimum_days');
            $table->unsignedInteger('maximum_days');
            $table->unsignedInteger('day_step');
            $table->bigInteger('customer_base_price_irr');
            $table->bigInteger('customer_price_per_gb_irr');
            $table->bigInteger('customer_price_per_day_irr');
            $table->bigInteger('customer_minimum_order_amount_irr');
            $table->bigInteger('agent_base_price_irr');
            $table->bigInteger('agent_price_per_gb_irr');
            $table->bigInteger('agent_price_per_day_irr');
            $table->bigInteger('agent_minimum_order_amount_irr');
            $table->boolean('discount_eligible')->default(false);
            $table->string('tag_match_mode', 16)->default('all');
            $table->string('username_mode', 32);
            $table->unsignedSmallInteger('username_minimum_length');
            $table->unsignedSmallInteger('username_maximum_length');
            $table->char('configuration_hash', 64);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
        });

        Schema::create('custom_plan_policy_tiers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('custom_plan_policy_id');
            $table->foreign('custom_plan_policy_id', 'custom_plan_tier_policy_fk')
                ->references('id')->on('custom_plan_policies')->restrictOnDelete();
            $table->string('tier_code', 32);
            $table->dateTime('created_at', 6);
            $table->unique(['custom_plan_policy_id', 'tier_code'], 'custom_plan_tier_unique');
        });

        Schema::create('custom_plan_policy_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('custom_plan_policy_id');
            $table->foreign('custom_plan_policy_id', 'custom_plan_tag_policy_fk')
                ->references('id')->on('custom_plan_policies')->restrictOnDelete();
            $table->unsignedBigInteger('customer_tag_id');
            $table->foreign('customer_tag_id', 'custom_plan_tag_customer_fk')
                ->references('id')->on('customer_tags')->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->unique(['custom_plan_policy_id', 'customer_tag_id'], 'custom_plan_tag_unique');
        });

        Schema::create('custom_plan_policy_separators', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('custom_plan_policy_id');
            $table->foreign('custom_plan_policy_id', 'custom_plan_separator_policy_fk')
                ->references('id')->on('custom_plan_policies')->restrictOnDelete();
            $table->char('separator', 1);
            $table->dateTime('created_at', 6);
            $table->unique(['custom_plan_policy_id', 'separator'], 'custom_plan_separator_unique');
        });

        Schema::create('custom_plan_policy_reserved_words', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('custom_plan_policy_id');
            $table->foreign('custom_plan_policy_id', 'custom_plan_word_policy_fk')
                ->references('id')->on('custom_plan_policies')->restrictOnDelete();
            $table->string('normalized_word', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['custom_plan_policy_id', 'normalized_word'], 'custom_plan_reserved_word_unique');
        });

        Schema::create('custom_plan_policy_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('custom_plan_policy_id');
            $table->foreign('custom_plan_policy_id', 'custom_plan_history_policy_fk')
                ->references('id')->on('custom_plan_policies')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->char('configuration_hash', 64);
            $table->boolean('enabled');
            $table->unsignedInteger('tier_count');
            $table->unsignedInteger('tag_count');
            $table->unsignedInteger('reserved_word_count');
            $table->unsignedBigInteger('actor_administrator_id');
            $table->foreign('actor_administrator_id', 'custom_plan_history_actor_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['custom_plan_policy_id', 'version'], 'custom_plan_history_version_unique');
        });

        Schema::create('custom_plan_calculations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('command_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->unsignedBigInteger('custom_plan_policy_id');
            $table->foreign('custom_plan_policy_id', 'custom_plan_calc_policy_fk')
                ->references('id')->on('custom_plan_policies')->restrictOnDelete();
            $table->unsignedBigInteger('custom_plan_policy_version');
            $table->char('policy_configuration_hash', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->string('tier_code_snapshot', 32)->nullable();
            $table->char('eligibility_snapshot_hash', 64);
            $table->unsignedInteger('data_gb');
            $table->unsignedInteger('days');
            $table->string('username_mode', 32);
            $table->string('normalized_username', 64);
            $table->bigInteger('base_price_irr');
            $table->bigInteger('price_per_gb_irr');
            $table->bigInteger('price_per_day_irr');
            $table->bigInteger('data_price_irr');
            $table->bigInteger('day_price_irr');
            $table->bigInteger('subtotal_irr');
            $table->bigInteger('minimum_order_amount_irr');
            $table->bigInteger('minimum_adjustment_irr');
            $table->bigInteger('final_price_irr');
            $table->boolean('discount_eligible');
            $table->string('correlation_id', 64);
            $table->string('source_code', 64);
            $table->string('reason_code', 64);
            $table->dateTime('created_at', 6);
            $table->index(['plan_offering_id', 'created_at'], 'custom_plan_calc_offering_created_idx');
            $table->index(['user_id', 'created_at'], 'custom_plan_calc_user_created_idx');
            $table->index(['normalized_username', 'created_at'], 'custom_plan_calc_username_created_idx');
        });

        Schema::create('service_username_registry', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('normalized_username', 64);
            $table->string('active_normalized_username', 64)->nullable()->unique();
            $table->string('state', 16)->default('active');
            $table->unsignedBigInteger('custom_plan_calculation_id')->nullable();
            $table->foreign('custom_plan_calculation_id', 'username_registry_calc_fk')
                ->references('id')->on('custom_plan_calculations')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['normalized_username', 'state'], 'username_registry_lookup_idx');
        });

        Schema::create('custom_plan_calculation_validations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('custom_plan_calculation_id');
            $table->foreign('custom_plan_calculation_id', 'custom_plan_validation_calc_fk')
                ->references('id')->on('custom_plan_calculations')->restrictOnDelete();
            $table->string('command_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->string('stage', 32);
            $table->char('policy_configuration_hash', 64);
            $table->char('eligibility_snapshot_hash', 64);
            $table->string('correlation_id', 64);
            $table->string('source_code', 64);
            $table->string('reason_code', 64);
            $table->dateTime('created_at', 6);
            $table->index(
                ['custom_plan_calculation_id', 'stage', 'created_at'],
                'custom_plan_validation_stage_idx',
            );
        });

        $this->addChecks();
        $this->createPolicyGuards();
        $this->createChildGuards();
        $this->createCalculationGuards();
        $this->createDependencyGuards();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('custom_plan_calculation_validations');
        Schema::dropIfExists('service_username_registry');
        Schema::dropIfExists('custom_plan_calculations');
        Schema::dropIfExists('custom_plan_policy_histories');
        Schema::dropIfExists('custom_plan_policy_reserved_words');
        Schema::dropIfExists('custom_plan_policy_separators');
        Schema::dropIfExists('custom_plan_policy_tags');
        Schema::dropIfExists('custom_plan_policy_tiers');
        Schema::dropIfExists('custom_plan_policies');
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_data_range_chk CHECK (`minimum_data_gb` >= 1 AND `maximum_data_gb` >= `minimum_data_gb` AND `data_step_gb` >= 1 AND MOD(`maximum_data_gb` - `minimum_data_gb`, `data_step_gb`) = 0)');
        DB::statement('ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_day_range_chk CHECK (`minimum_days` >= 1 AND `maximum_days` >= `minimum_days` AND `day_step` >= 1 AND MOD(`maximum_days` - `minimum_days`, `day_step`) = 0)');
        DB::statement('ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_prices_chk CHECK (`customer_base_price_irr` >= 0 AND `customer_price_per_gb_irr` >= 0 AND `customer_price_per_day_irr` >= 0 AND `customer_minimum_order_amount_irr` >= 0 AND `agent_base_price_irr` >= 0 AND `agent_price_per_gb_irr` >= 0 AND `agent_price_per_day_irr` >= 0 AND `agent_minimum_order_amount_irr` >= 0)');
        DB::statement("ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_tag_match_chk CHECK (`tag_match_mode` IN ('any', 'all'))");
        DB::statement("ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_username_mode_chk CHECK (`username_mode` IN ('telegram_user_id', 'telegram_user_id_suffix', 'customer_selected'))");
        DB::statement('ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_username_length_chk CHECK (`username_minimum_length` >= 3 AND `username_maximum_length` >= `username_minimum_length` AND `username_maximum_length` <= 64)');
        DB::statement('ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_hash_chk CHECK (CHAR_LENGTH(`configuration_hash`) = 64)');
        DB::statement('ALTER TABLE custom_plan_policies ADD CONSTRAINT custom_plan_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE custom_plan_policy_tiers ADD CONSTRAINT custom_plan_tier_code_chk CHECK (`tier_code` IN ('new', 'normal', 'loyal', 'vip'))");
        DB::statement("ALTER TABLE custom_plan_policy_separators ADD CONSTRAINT custom_plan_separator_chk CHECK (`separator` IN ('_', '-', '.'))");
        DB::statement('ALTER TABLE custom_plan_policy_reserved_words ADD CONSTRAINT custom_plan_word_chk CHECK (CHAR_LENGTH(`normalized_word`) >= 1)');

        DB::statement("ALTER TABLE custom_plan_calculations ADD CONSTRAINT custom_plan_calc_actor_chk CHECK (`actor_type` IN ('customer', 'agent'))");
        DB::statement("ALTER TABLE custom_plan_calculations ADD CONSTRAINT custom_plan_calc_username_mode_chk CHECK (`username_mode` IN ('telegram_user_id', 'telegram_user_id_suffix', 'customer_selected'))");
        DB::statement('ALTER TABLE custom_plan_calculations ADD CONSTRAINT custom_plan_calc_amounts_chk CHECK (`base_price_irr` >= 0 AND `price_per_gb_irr` >= 0 AND `price_per_day_irr` >= 0 AND `data_price_irr` >= 0 AND `day_price_irr` >= 0 AND `subtotal_irr` >= 0 AND `minimum_order_amount_irr` >= 0 AND `minimum_adjustment_irr` >= 0 AND `final_price_irr` >= 0)');
        DB::statement('ALTER TABLE custom_plan_calculations ADD CONSTRAINT custom_plan_calc_formula_chk CHECK (`data_price_irr` = `data_gb` * `price_per_gb_irr` AND `day_price_irr` = `days` * `price_per_day_irr` AND `subtotal_irr` = `base_price_irr` + `data_price_irr` + `day_price_irr` AND `minimum_adjustment_irr` = GREATEST(0, `minimum_order_amount_irr` - `subtotal_irr`) AND `final_price_irr` = `subtotal_irr` + `minimum_adjustment_irr`)');
        DB::statement('ALTER TABLE custom_plan_calculations ADD CONSTRAINT custom_plan_calc_hashes_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64 AND CHAR_LENGTH(`policy_configuration_hash`) = 64 AND CHAR_LENGTH(`eligibility_snapshot_hash`) = 64)');
        DB::statement('ALTER TABLE custom_plan_calculations ADD CONSTRAINT custom_plan_calc_username_chk CHECK (CHAR_LENGTH(`normalized_username`) >= 3 AND CHAR_LENGTH(`normalized_username`) <= 64)');

        DB::statement("ALTER TABLE service_username_registry ADD CONSTRAINT username_registry_state_chk CHECK (`state` IN ('active', 'released'))");
        DB::statement('ALTER TABLE service_username_registry ADD CONSTRAINT username_registry_active_chk CHECK ((`state` = \'active\' AND `active_normalized_username` = `normalized_username`) OR (`state` = \'released\' AND `active_normalized_username` IS NULL))');
        DB::statement('ALTER TABLE service_username_registry ADD CONSTRAINT username_registry_version_chk CHECK (`version` >= 1)');

        DB::statement("ALTER TABLE custom_plan_calculation_validations ADD CONSTRAINT custom_plan_validation_stage_chk CHECK (`stage` IN ('pre_payment', 'pre_provisioning'))");
        DB::statement('ALTER TABLE custom_plan_calculation_validations ADD CONSTRAINT custom_plan_validation_hashes_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64 AND CHAR_LENGTH(`policy_configuration_hash`) = 64 AND CHAR_LENGTH(`eligibility_snapshot_hash`) = 64)');
    }

    private function createPolicyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_policies_insert_guard
BEFORE INSERT ON custom_plan_policies
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings
        WHERE id = NEW.plan_offering_id AND state = 'draft' AND custom_plan_allowed = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan policy requires a draft custom-plan Offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_policies_update_guard
BEFORE UPDATE ON custom_plan_policies
FOR EACH ROW
BEGIN
    IF NOT (OLD.plan_offering_id <=> NEW.plan_offering_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan policy Offering is immutable.';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan policy version must increment once.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings
        WHERE id = OLD.plan_offering_id AND state = 'draft' AND custom_plan_allowed = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan policy is mutable only for a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER custom_plan_histories_update_guard BEFORE UPDATE ON custom_plan_policy_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan policy history is append-only.'");
        DB::unprepared("CREATE TRIGGER custom_plan_histories_delete_guard BEFORE DELETE ON custom_plan_policy_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan policy history is append-only.'");
    }

    private function createChildGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_tiers_insert_guard BEFORE INSERT ON custom_plan_policy_tiers FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = NEW.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan tier requires a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_tiers_delete_guard BEFORE DELETE ON custom_plan_policy_tiers FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = OLD.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan tier is removable only for a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER custom_plan_tiers_update_guard BEFORE UPDATE ON custom_plan_policy_tiers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan tier rows are replace-only.'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_tags_insert_guard BEFORE INSERT ON custom_plan_policy_tags FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = NEW.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan tag requires a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_tags_delete_guard BEFORE DELETE ON custom_plan_policy_tags FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = OLD.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan tag is removable only for a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER custom_plan_tags_update_guard BEFORE UPDATE ON custom_plan_policy_tags FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan tag rows are replace-only.'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_separators_insert_guard BEFORE INSERT ON custom_plan_policy_separators FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = NEW.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan separator requires a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_separators_delete_guard BEFORE DELETE ON custom_plan_policy_separators FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = OLD.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan separator is removable only for a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER custom_plan_separators_update_guard BEFORE UPDATE ON custom_plan_policy_separators FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan separator rows are replace-only.'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_words_insert_guard BEFORE INSERT ON custom_plan_policy_reserved_words FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = NEW.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan reserved word requires a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_words_delete_guard BEFORE DELETE ON custom_plan_policy_reserved_words FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM custom_plan_policies policy INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id WHERE policy.id = OLD.custom_plan_policy_id AND offering.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan reserved word is removable only for a draft Offering.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER custom_plan_words_update_guard BEFORE UPDATE ON custom_plan_policy_reserved_words FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan reserved-word rows are replace-only.'");
    }

    private function createCalculationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_calculations_insert_guard
BEFORE INSERT ON custom_plan_calculations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM custom_plan_policies policy
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE policy.id = NEW.custom_plan_policy_id
          AND policy.plan_offering_id = NEW.plan_offering_id
          AND policy.enabled = 1
          AND policy.version = NEW.custom_plan_policy_version
          AND policy.configuration_hash = NEW.policy_configuration_hash
          AND offering.state = 'active'
          AND offering.visibility = 'visible'
          AND offering.custom_plan_allowed = 1
          AND NEW.data_gb BETWEEN policy.minimum_data_gb AND policy.maximum_data_gb
          AND MOD(NEW.data_gb - policy.minimum_data_gb, policy.data_step_gb) = 0
          AND NEW.days BETWEEN policy.minimum_days AND policy.maximum_days
          AND MOD(NEW.days - policy.minimum_days, policy.day_step) = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan calculation policy snapshot is invalid.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER custom_plan_calculations_update_guard BEFORE UPDATE ON custom_plan_calculations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan calculations are immutable.'");
        DB::unprepared("CREATE TRIGGER custom_plan_calculations_delete_guard BEFORE DELETE ON custom_plan_calculations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan calculations are immutable.'");
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_validations_insert_guard
BEFORE INSERT ON custom_plan_calculation_validations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM custom_plan_calculations calculation
        WHERE calculation.id = NEW.custom_plan_calculation_id
          AND calculation.policy_configuration_hash = NEW.policy_configuration_hash
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan validation snapshot is invalid.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER custom_plan_validations_update_guard BEFORE UPDATE ON custom_plan_calculation_validations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan validations are append-only.'");
        DB::unprepared("CREATE TRIGGER custom_plan_validations_delete_guard BEFORE DELETE ON custom_plan_calculation_validations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan validations are append-only.'");
    }

    private function createDependencyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_offering_activation_guard
BEFORE UPDATE ON plan_offerings
FOR EACH ROW
BEGIN
    IF NEW.custom_plan_allowed = 1
       AND ((NEW.state = 'active' AND OLD.state <> 'active') OR (NEW.visibility = 'visible' AND OLD.visibility <> 'visible'))
       AND NOT EXISTS (
            SELECT 1 FROM custom_plan_policies
            WHERE plan_offering_id = NEW.id AND enabled = 1
       )
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan Offering requires an enabled policy.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_customer_tags_disable_guard
BEFORE UPDATE ON customer_tags
FOR EACH ROW
BEGIN
    IF NEW.is_active = 0 AND OLD.is_active = 1 AND EXISTS (
        SELECT 1 FROM custom_plan_policy_tags policy_tag
        INNER JOIN custom_plan_policies policy ON policy.id = policy_tag.custom_plan_policy_id
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE policy_tag.customer_tag_id = OLD.id AND policy.enabled = 1 AND offering.state = 'active'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active custom-plan policy depends on customer tag.';
    END IF;
END
SQL);
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_policies_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_policies_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_histories_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_tiers_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_tiers_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_tiers_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_tags_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_tags_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_tags_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_separators_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_separators_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_separators_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_words_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_words_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_words_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_calculations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_calculations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_calculations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_validations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_validations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_validations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_offering_activation_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_customer_tags_disable_guard');
    }
};
