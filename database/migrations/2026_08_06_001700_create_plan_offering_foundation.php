<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-002 CAT-003 CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('plan_offerings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('sales_server_id')->constrained('sales_servers')->restrictOnDelete();
            $table->foreignId('panel_service_target_id')->constrained('panel_service_targets')->restrictOnDelete();
            $table->string('service_mode_code', 64);
            $table->string('service_mode_label_fa', 191);
            $table->string('service_mode_label_en', 191)->nullable();
            $table->string('audience', 32);
            $table->string('server_selection_mode', 32);
            $table->string('protocol_selection_mode', 32);
            $table->string('tag_match_mode', 16)->default('all');
            $table->bigInteger('base_price_irr');
            $table->unsignedInteger('duration_days');
            $table->bigInteger('data_allowance_bytes')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('min_purchase_quantity')->default(1);
            $table->unsignedInteger('max_purchase_quantity')->default(1);
            $table->boolean('discount_eligible')->default(true);
            $table->boolean('auto_renew_allowed')->default(false);
            $table->boolean('custom_plan_allowed')->default(false);
            $table->boolean('trial_allowed')->default(false);
            $table->string('state', 32)->default('draft');
            $table->string('visibility', 32)->default('hidden');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['product_id', 'state', 'visibility', 'sort_order'], 'plan_offerings_product_listing_idx');
            $table->index(['sales_server_id', 'state', 'visibility'], 'plan_offerings_server_listing_idx');
            $table->index(['panel_service_target_id', 'state'], 'plan_offerings_target_state_idx');
        });

        Schema::create('plan_offering_tiers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->cascadeOnDelete();
            $table->string('tier_code', 32);
            $table->dateTime('created_at', 6);
            $table->unique(['plan_offering_id', 'tier_code'], 'plan_offering_tier_unique');
        });

        Schema::create('plan_offering_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_tag_id');
            $table->foreign('customer_tag_id', 'plan_offering_tag_customer_fk')
                ->references('id')
                ->on('customer_tags')
                ->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->unique(['plan_offering_id', 'customer_tag_id'], 'plan_offering_tag_unique');
        });

        Schema::create('plan_offering_protocol_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->cascadeOnDelete();
            $table->unsignedBigInteger('panel_protocol_profile_id');
            $table->foreign('panel_protocol_profile_id', 'plan_offering_profile_parent_fk')
                ->references('id')
                ->on('panel_protocol_profiles')
                ->restrictOnDelete();
            $table->boolean('customer_selectable')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps(6);
            $table->unique(['plan_offering_id', 'panel_protocol_profile_id'], 'plan_offering_profile_unique');
        });

        Schema::create('plan_offering_required_capabilities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->cascadeOnDelete();
            $table->string('capability_code', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['plan_offering_id', 'capability_code'], 'plan_offering_capability_unique');
        });

        Schema::create('plan_offering_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->cascadeOnDelete();
            $table->string('operation_code', 32);
            $table->boolean('customer_enabled')->default(false);
            $table->boolean('administrator_enabled')->default(false);
            $table->bigInteger('price_irr')->default(0);
            $table->boolean('discount_eligible')->default(false);
            $table->string('required_capability_code', 64)->nullable();
            $table->timestamps(6);
            $table->unique(['plan_offering_id', 'operation_code'], 'plan_offering_operation_unique');
        });

        Schema::create('plan_offering_packages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('package_type', 32);
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->bigInteger('price_irr');
            $table->unsignedInteger('duration_days')->nullable();
            $table->bigInteger('data_bytes')->nullable();
            $table->boolean('discount_eligible')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps(6);
            $table->unique(['plan_offering_id', 'code'], 'plan_offering_package_unique');
            $table->index(['plan_offering_id', 'package_type', 'sort_order'], 'plan_offering_package_listing_idx');
        });

        Schema::create('plan_offering_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('action', 96);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('from_visibility', 32)->nullable();
            $table->string('to_visibility', 32);
            $table->char('from_configuration_hash', 64)->nullable();
            $table->char('to_configuration_hash', 64);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['plan_offering_id', 'version'], 'plan_offering_history_version_unique');
            $table->index(['plan_offering_id', 'created_at'], 'plan_offering_history_created_idx');
        });

        $this->addChecks();
        $this->createOfferingTriggers();
        $this->createDependencyGuards();
        $this->createChildGuards();
        $this->createHistoryGuards();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('plan_offering_histories');
        Schema::dropIfExists('plan_offering_packages');
        Schema::dropIfExists('plan_offering_operations');
        Schema::dropIfExists('plan_offering_required_capabilities');
        Schema::dropIfExists('plan_offering_protocol_profiles');
        Schema::dropIfExists('plan_offering_tags');
        Schema::dropIfExists('plan_offering_tiers');
        Schema::dropIfExists('plan_offerings');
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_audience_chk CHECK (`audience` IN ('customers', 'agents', 'both'))");
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_server_selection_chk CHECK (`server_selection_mode` IN ('customer_selects', 'system_selects', 'hybrid'))");
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_protocol_selection_chk CHECK (`protocol_selection_mode` IN ('fixed', 'customer_selects', 'system_selects'))");
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_tag_match_chk CHECK (`tag_match_mode` IN ('any', 'all'))");
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_state_chk CHECK (`state` IN ('draft', 'active', 'archived'))");
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_visibility_chk CHECK (`visibility` IN ('hidden', 'visible'))");
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_visible_active_chk CHECK (`visibility` = 'hidden' OR `state` = 'active')");
        DB::statement("ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_archived_hidden_chk CHECK (`state` <> 'archived' OR `visibility` = 'hidden')");
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_price_chk CHECK (`base_price_irr` >= 0)');
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_duration_chk CHECK (`duration_days` >= 1)');
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_data_chk CHECK (`data_allowance_bytes` IS NULL OR `data_allowance_bytes` >= 1)');
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_device_chk CHECK (`device_limit` IS NULL OR `device_limit` >= 1)');
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_quantity_chk CHECK (`min_purchase_quantity` >= 1 AND `max_purchase_quantity` >= `min_purchase_quantity`)');
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_mode_label_chk CHECK (CHAR_LENGTH(`service_mode_label_fa`) > 0)');
        DB::statement('ALTER TABLE plan_offerings ADD CONSTRAINT plan_offerings_mode_code_chk CHECK (CHAR_LENGTH(`service_mode_code`) > 0)');

        DB::statement("ALTER TABLE plan_offering_tiers ADD CONSTRAINT plan_offering_tiers_code_chk CHECK (`tier_code` IN ('new', 'normal', 'loyal', 'vip'))");
        DB::statement("ALTER TABLE plan_offering_operations ADD CONSTRAINT plan_offering_operations_code_chk CHECK (`operation_code` IN ('renew', 'add_data', 'add_days', 'add_data_days', 'reset_usage', 'change_plan'))");
        DB::statement('ALTER TABLE plan_offering_operations ADD CONSTRAINT plan_offering_operations_audience_chk CHECK (`customer_enabled` = 1 OR `administrator_enabled` = 1)');
        DB::statement('ALTER TABLE plan_offering_operations ADD CONSTRAINT plan_offering_operations_price_chk CHECK (`price_irr` >= 0)');
        DB::statement('ALTER TABLE plan_offering_operations ADD CONSTRAINT plan_offering_operations_capability_chk CHECK (`required_capability_code` IS NULL OR CHAR_LENGTH(`required_capability_code`) > 0)');

        DB::statement("ALTER TABLE plan_offering_packages ADD CONSTRAINT plan_offering_packages_type_chk CHECK (`package_type` IN ('renewal', 'add_data', 'add_days', 'add_data_days'))");
        DB::statement('ALTER TABLE plan_offering_packages ADD CONSTRAINT plan_offering_packages_price_chk CHECK (`price_irr` >= 0)');
        DB::statement('ALTER TABLE plan_offering_packages ADD CONSTRAINT plan_offering_packages_name_chk CHECK (CHAR_LENGTH(`name_fa`) > 0)');
        DB::statement('ALTER TABLE plan_offering_packages ADD CONSTRAINT plan_offering_packages_data_chk CHECK (`data_bytes` IS NULL OR `data_bytes` >= 1)');
        DB::statement('ALTER TABLE plan_offering_packages ADD CONSTRAINT plan_offering_packages_duration_chk CHECK (`duration_days` IS NULL OR `duration_days` >= 1)');
        DB::statement("ALTER TABLE plan_offering_packages ADD CONSTRAINT plan_offering_packages_shape_chk CHECK ((`package_type` = 'renewal' AND `duration_days` IS NOT NULL) OR (`package_type` = 'add_data' AND `duration_days` IS NULL AND `data_bytes` IS NOT NULL) OR (`package_type` = 'add_days' AND `duration_days` IS NOT NULL AND `data_bytes` IS NULL) OR (`package_type` = 'add_data_days' AND `duration_days` IS NOT NULL AND `data_bytes` IS NOT NULL))");
    }

    private function createOfferingTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offerings_insert_guard
BEFORE INSERT ON plan_offerings
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering activation requires validated dependencies.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offerings_update_guard
BEFORE UPDATE ON plan_offerings
FOR EACH ROW
BEGIN
    IF OLD.state IN ('active', 'archived') AND (
        NOT (OLD.product_id <=> NEW.product_id)
        OR NOT (OLD.variant_id <=> NEW.variant_id)
        OR NOT (OLD.sales_server_id <=> NEW.sales_server_id)
        OR NOT (OLD.panel_service_target_id <=> NEW.panel_service_target_id)
        OR NOT (OLD.service_mode_code <=> NEW.service_mode_code)
        OR NOT (OLD.service_mode_label_fa <=> NEW.service_mode_label_fa)
        OR NOT (OLD.service_mode_label_en <=> NEW.service_mode_label_en)
        OR NOT (OLD.audience <=> NEW.audience)
        OR NOT (OLD.server_selection_mode <=> NEW.server_selection_mode)
        OR NOT (OLD.protocol_selection_mode <=> NEW.protocol_selection_mode)
        OR NOT (OLD.tag_match_mode <=> NEW.tag_match_mode)
        OR NOT (OLD.base_price_irr <=> NEW.base_price_irr)
        OR NOT (OLD.duration_days <=> NEW.duration_days)
        OR NOT (OLD.data_allowance_bytes <=> NEW.data_allowance_bytes)
        OR NOT (OLD.device_limit <=> NEW.device_limit)
        OR NOT (OLD.min_purchase_quantity <=> NEW.min_purchase_quantity)
        OR NOT (OLD.max_purchase_quantity <=> NEW.max_purchase_quantity)
        OR NOT (OLD.discount_eligible <=> NEW.discount_eligible)
        OR NOT (OLD.auto_renew_allowed <=> NEW.auto_renew_allowed)
        OR NOT (OLD.custom_plan_allowed <=> NEW.custom_plan_allowed)
        OR NOT (OLD.trial_allowed <=> NEW.trial_allowed)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active or archived plan offering configuration is immutable.';
    END IF;

    IF OLD.state = 'archived' AND (
        NEW.state <> 'archived'
        OR NEW.visibility <> OLD.visibility
        OR NEW.sort_order <> OLD.sort_order
        OR NEW.version <> OLD.version
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Archived plan offering is immutable.';
    END IF;

    IF (NEW.state = 'active' AND OLD.state <> 'active') OR (NEW.visibility = 'visible' AND OLD.visibility <> 'visible') THEN
        IF NOT EXISTS (
            SELECT 1 FROM products p
            WHERE p.id = NEW.product_id AND p.state = 'active' AND p.visibility = 'visible'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering product is not operational.';
        END IF;

        IF NEW.variant_id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM product_variants v
            WHERE v.id = NEW.variant_id AND v.product_id = NEW.product_id AND v.state = 'active'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering variant is not operational.';
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM sales_servers s
            WHERE s.id = NEW.sales_server_id AND s.state = 'active' AND s.visibility = 'listed'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering sales server is not operational.';
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM panel_service_targets t
            INNER JOIN panel_connections c ON c.id = t.panel_connection_id
            WHERE t.id = NEW.panel_service_target_id
              AND t.state = 'active'
              AND t.capability_status = 'verified'
              AND c.state = 'active'
              AND t.verified_connection_version = c.version
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering target is not operational.';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM plan_offering_tags eligibility
            LEFT JOIN customer_tags tag ON tag.id = eligibility.customer_tag_id AND tag.is_active = 1
            WHERE eligibility.plan_offering_id = OLD.id AND tag.id IS NULL
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering eligibility tag is unavailable.';
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM plan_offering_protocol_profiles p
            WHERE p.plan_offering_id = OLD.id
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering requires a protocol profile.';
        END IF;

        IF (SELECT COUNT(*) FROM plan_offering_protocol_profiles p WHERE p.plan_offering_id = OLD.id AND p.is_default = 1) <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering requires exactly one default protocol.';
        END IF;

        IF NEW.protocol_selection_mode = 'fixed' AND (
            (SELECT COUNT(*) FROM plan_offering_protocol_profiles p WHERE p.plan_offering_id = OLD.id) <> 1
            OR EXISTS (SELECT 1 FROM plan_offering_protocol_profiles p WHERE p.plan_offering_id = OLD.id AND p.customer_selectable = 1)
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixed protocol selection is invalid.';
        END IF;

        IF NEW.protocol_selection_mode = 'customer_selects' AND NOT EXISTS (
            SELECT 1 FROM plan_offering_protocol_profiles p WHERE p.plan_offering_id = OLD.id AND p.customer_selectable = 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer protocol selection requires a selectable profile.';
        END IF;

        IF NEW.protocol_selection_mode <> 'customer_selects' AND EXISTS (
            SELECT 1 FROM plan_offering_protocol_profiles p WHERE p.plan_offering_id = OLD.id AND p.customer_selectable = 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Selectable profiles require customer protocol mode.';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM plan_offering_protocol_profiles op
            LEFT JOIN panel_protocol_profiles p ON p.id = op.panel_protocol_profile_id AND p.state = 'active'
            LEFT JOIN panel_target_protocol_profiles tp
              ON tp.panel_service_target_id = NEW.panel_service_target_id
             AND tp.panel_protocol_profile_id = op.panel_protocol_profile_id
            WHERE op.plan_offering_id = OLD.id AND (p.id IS NULL OR tp.id IS NULL)
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering protocol profile is incompatible.';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM plan_offering_required_capabilities required
            LEFT JOIN panel_target_capabilities capability
              ON capability.panel_service_target_id = NEW.panel_service_target_id
             AND capability.capability_code = required.capability_code
             AND capability.verification_status = 'verified'
            WHERE required.plan_offering_id = OLD.id AND capability.id IS NULL
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering required capability is unavailable.';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM plan_offering_operations operation_policy
            LEFT JOIN panel_target_capabilities capability
              ON capability.panel_service_target_id = NEW.panel_service_target_id
             AND capability.capability_code = operation_policy.required_capability_code
             AND capability.verification_status = 'verified'
            WHERE operation_policy.plan_offering_id = OLD.id
              AND operation_policy.required_capability_code IS NOT NULL
              AND capability.id IS NULL
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering operation capability is unavailable.';
        END IF;
    END IF;
END
SQL);
    }

    private function createDependencyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER products_offering_guard
BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
    IF NEW.state = 'archived' AND OLD.state <> 'archived'
       AND EXISTS (SELECT 1 FROM plan_offerings o WHERE o.product_id = OLD.id AND o.state <> 'archived')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product has non-archived plan offerings.';
    END IF;

    IF NEW.visibility = 'hidden' AND OLD.visibility = 'visible'
       AND EXISTS (SELECT 1 FROM plan_offerings o WHERE o.product_id = OLD.id AND o.visibility = 'visible')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product has visible plan offerings.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER product_variants_offering_guard
BEFORE UPDATE ON product_variants
FOR EACH ROW
BEGIN
    IF NEW.state = 'archived' AND OLD.state <> 'archived'
       AND EXISTS (SELECT 1 FROM plan_offerings o WHERE o.variant_id = OLD.id AND o.state <> 'archived')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Variant has non-archived plan offerings.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER sales_servers_offering_guard
BEFORE UPDATE ON sales_servers
FOR EACH ROW
BEGIN
    IF NEW.state = 'archived' AND OLD.state <> 'archived'
       AND EXISTS (SELECT 1 FROM plan_offerings o WHERE o.sales_server_id = OLD.id AND o.state <> 'archived')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales server has non-archived plan offerings.';
    END IF;

    IF (NEW.state <> 'active' OR NEW.visibility <> 'listed')
       AND EXISTS (SELECT 1 FROM plan_offerings o WHERE o.sales_server_id = OLD.id AND o.visibility = 'visible')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales server has visible plan offerings.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_targets_offering_guard
BEFORE UPDATE ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'archived' AND OLD.state <> 'archived'
       AND EXISTS (SELECT 1 FROM plan_offerings o WHERE o.panel_service_target_id = OLD.id AND o.state <> 'archived')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target has non-archived plan offerings.';
    END IF;

    IF (NEW.state <> 'active' OR NEW.capability_status <> 'verified')
       AND EXISTS (SELECT 1 FROM plan_offerings o WHERE o.panel_service_target_id = OLD.id AND o.visibility = 'visible')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target has visible plan offerings.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_profiles_offering_guard
BEFORE UPDATE ON panel_protocol_profiles
FOR EACH ROW
BEGIN
    IF NEW.state <> 'active'
       AND EXISTS (
           SELECT 1
           FROM plan_offering_protocol_profiles assignment
           INNER JOIN plan_offerings offering ON offering.id = assignment.plan_offering_id
           WHERE assignment.panel_protocol_profile_id = OLD.id AND offering.state = 'active'
       )
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protocol profile has active plan offerings.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER customer_tags_offering_guard
BEFORE UPDATE ON customer_tags
FOR EACH ROW
BEGIN
    IF NEW.is_active = 0 AND OLD.is_active = 1
       AND EXISTS (
           SELECT 1
           FROM plan_offering_tags eligibility
           INNER JOIN plan_offerings offering ON offering.id = eligibility.plan_offering_id
           WHERE eligibility.customer_tag_id = OLD.id AND offering.state = 'active'
       )
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer tag has active plan offerings.';
    END IF;
END
SQL);
    }

    private function createChildGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_tiers_insert_guard
BEFORE INSERT ON plan_offering_tiers
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = NEW.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_tiers_update_guard
BEFORE UPDATE ON plan_offering_tiers
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_tiers_delete_guard
BEFORE DELETE ON plan_offering_tiers
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_tags_insert_guard
BEFORE INSERT ON plan_offering_tags
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = NEW.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_tags_update_guard
BEFORE UPDATE ON plan_offering_tags
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_tags_delete_guard
BEFORE DELETE ON plan_offering_tags
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_profiles_insert_guard
BEFORE INSERT ON plan_offering_protocol_profiles
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = NEW.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_profiles_update_guard
BEFORE UPDATE ON plan_offering_protocol_profiles
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_profiles_delete_guard
BEFORE DELETE ON plan_offering_protocol_profiles
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_capabilities_insert_guard
BEFORE INSERT ON plan_offering_required_capabilities
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = NEW.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_capabilities_update_guard
BEFORE UPDATE ON plan_offering_required_capabilities
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_capabilities_delete_guard
BEFORE DELETE ON plan_offering_required_capabilities
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_operations_insert_guard
BEFORE INSERT ON plan_offering_operations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = NEW.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_operations_update_guard
BEFORE UPDATE ON plan_offering_operations
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_operations_delete_guard
BEFORE DELETE ON plan_offering_operations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_packages_insert_guard
BEFORE INSERT ON plan_offering_packages
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = NEW.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_packages_update_guard
BEFORE UPDATE ON plan_offering_packages
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER plan_offering_packages_delete_guard
BEFORE DELETE ON plan_offering_packages
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings o WHERE o.id = OLD.plan_offering_id AND o.state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering children require draft state.';
    END IF;
END
SQL);
    }

    private function createHistoryGuards(): void
    {
        DB::unprepared("CREATE TRIGGER plan_offering_histories_update_guard BEFORE UPDATE ON plan_offering_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering history is append-only.'");
        DB::unprepared("CREATE TRIGGER plan_offering_histories_delete_guard BEFORE DELETE ON plan_offering_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan offering history is append-only.'");
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offerings_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offerings_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS products_offering_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS product_variants_offering_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sales_servers_offering_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_targets_offering_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_profiles_offering_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS customer_tags_offering_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_tiers_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_tiers_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_tiers_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_tags_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_tags_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_tags_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_profiles_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_profiles_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_profiles_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_capabilities_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_capabilities_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_capabilities_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_operations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_operations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_operations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_packages_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_packages_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_packages_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_offering_histories_delete_guard');
    }
};
