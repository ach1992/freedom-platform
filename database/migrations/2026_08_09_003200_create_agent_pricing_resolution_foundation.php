<?php

declare(strict_types=1);

use App\Modules\Agents\Infrastructure\AgentPricingMigrationGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement AGT-005 BUY-002 ACL-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('agent_pricing_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('profile_code', 64)->unique();
            $table->dateTime('created_at', 6);
        });

        Schema::create('agent_pricing_profile_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('agent_pricing_profile_id')->constrained('agent_pricing_profiles')->restrictOnDelete();
            $table->string('mutation_key', 128)->unique();
            $table->char('mutation_payload_hash', 64);
            $table->unsignedBigInteger('version');
            $table->string('state', 16);
            $table->boolean('discount_combination_allowed')->default(false);
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['agent_pricing_profile_id', 'version'], 'agent_price_profile_version_unique');
            $table->index(['agent_pricing_profile_id', 'state', 'version'], 'agent_price_profile_state_idx');
        });

        Schema::create('agent_pricing_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('agent_pricing_profile_id')->constrained('agent_pricing_profiles')->restrictOnDelete();
            $table->string('rule_code', 128);
            $table->dateTime('created_at', 6);
            $table->unique(['agent_pricing_profile_id', 'rule_code'], 'agent_price_profile_rule_unique');
        });

        Schema::create('agent_pricing_rule_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('agent_pricing_rule_id')->constrained('agent_pricing_rules')->restrictOnDelete();
            $table->string('mutation_key', 128)->unique();
            $table->char('mutation_payload_hash', 64);
            $table->unsignedBigInteger('version');
            $table->string('state', 16);
            $table->bigInteger('override_price_irr');
            $table->string('action', 32)->nullable();
            $table->foreignId('plan_offering_id')->nullable()->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('sales_server_id')->nullable()->constrained('sales_servers')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['agent_pricing_rule_id', 'version'], 'agent_price_rule_version_unique');
            $table->index(['state', 'agent_pricing_rule_id', 'version'], 'agent_price_rule_state_idx');
            $table->index(['plan_offering_id', 'product_id', 'sales_server_id', 'action'], 'agent_price_rule_scope_idx');
        });

        Schema::create('agent_pricing_resolutions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('resolution_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('agent_profile_id')->constrained('agent_profiles')->restrictOnDelete();
            $table->foreignId('agent_pricing_profile_id')->constrained('agent_pricing_profiles')->restrictOnDelete();
            $table->foreignId('agent_pricing_profile_version_id')->constrained('agent_pricing_profile_versions')->restrictOnDelete();
            $table->ulid('pricing_profile_public_id_snapshot');
            $table->string('pricing_profile_code_snapshot', 64);
            $table->unsignedBigInteger('pricing_profile_version');
            $table->char('pricing_profile_configuration_hash', 64);
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('product_id_snapshot')->constrained('products')->restrictOnDelete();
            $table->foreignId('sales_server_id_snapshot')->constrained('sales_servers')->restrictOnDelete();
            $table->string('action', 32);
            $table->foreignId('agent_pricing_rule_id')->nullable()->constrained('agent_pricing_rules')->restrictOnDelete();
            $table->foreignId('agent_pricing_rule_version_id')->nullable()->constrained('agent_pricing_rule_versions')->restrictOnDelete();
            $table->ulid('rule_public_id_snapshot')->nullable();
            $table->string('rule_code_snapshot', 128)->nullable();
            $table->unsignedBigInteger('rule_version')->nullable();
            $table->char('rule_configuration_hash', 64)->nullable();
            $table->bigInteger('override_price_irr')->nullable();
            $table->boolean('discount_combination_allowed');
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at'], 'agent_price_resolution_user_idx');
            $table->index(['agent_pricing_profile_version_id', 'created_at'], 'agent_price_resolution_profile_idx');
            $table->index(['agent_pricing_rule_version_id', 'created_at'], 'agent_price_resolution_rule_idx');
        });

        $this->addChecks();
        AgentPricingMigrationGuards::create();
    }

    public function down(): void
    {
        AgentPricingMigrationGuards::drop();

        Schema::dropIfExists('agent_pricing_resolutions');
        Schema::dropIfExists('agent_pricing_rule_versions');
        Schema::dropIfExists('agent_pricing_rules');
        Schema::dropIfExists('agent_pricing_profile_versions');
        Schema::dropIfExists('agent_pricing_profiles');
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE agent_pricing_profiles ADD CONSTRAINT agent_price_profile_code_chk CHECK (`profile_code` REGEXP '^[a-z0-9_.-]{1,64}$')");
        DB::statement("ALTER TABLE agent_pricing_profile_versions ADD CONSTRAINT agent_price_profile_state_chk CHECK (`state` IN ('draft', 'active', 'disabled', 'archived'))");
        DB::statement('ALTER TABLE agent_pricing_profile_versions ADD CONSTRAINT agent_price_profile_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE agent_pricing_profile_versions ADD CONSTRAINT agent_price_profile_hashes_chk CHECK (`mutation_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE agent_pricing_profile_versions ADD CONSTRAINT agent_price_profile_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 16 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE agent_pricing_rules ADD CONSTRAINT agent_price_rule_code_chk CHECK (`rule_code` REGEXP '^[a-z0-9_.:-]{1,128}$')");
        DB::statement("ALTER TABLE agent_pricing_rule_versions ADD CONSTRAINT agent_price_rule_state_chk CHECK (`state` IN ('draft', 'active', 'disabled', 'archived'))");
        DB::statement('ALTER TABLE agent_pricing_rule_versions ADD CONSTRAINT agent_price_rule_amount_chk CHECK (`override_price_irr` >= 0)');
        DB::statement("ALTER TABLE agent_pricing_rule_versions ADD CONSTRAINT agent_price_rule_action_chk CHECK (`action` IS NULL OR `action` IN ('purchase', 'renew', 'add_data', 'add_days'))");
        DB::statement('ALTER TABLE agent_pricing_rule_versions ADD CONSTRAINT agent_price_rule_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE agent_pricing_rule_versions ADD CONSTRAINT agent_price_rule_hashes_chk CHECK (`mutation_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE agent_pricing_rule_versions ADD CONSTRAINT agent_price_rule_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 16 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE agent_pricing_resolutions ADD CONSTRAINT agent_price_resolution_action_chk CHECK (`action` IN ('purchase', 'renew', 'add_data', 'add_days'))");
        DB::statement('ALTER TABLE agent_pricing_resolutions ADD CONSTRAINT agent_price_resolution_profile_version_chk CHECK (`pricing_profile_version` >= 1)');
        DB::statement("ALTER TABLE agent_pricing_resolutions ADD CONSTRAINT agent_price_resolution_rule_shape_chk CHECK ((`agent_pricing_rule_id` IS NULL AND `agent_pricing_rule_version_id` IS NULL AND `rule_public_id_snapshot` IS NULL AND `rule_code_snapshot` IS NULL AND `rule_version` IS NULL AND `rule_configuration_hash` IS NULL AND `override_price_irr` IS NULL) OR (`agent_pricing_rule_id` IS NOT NULL AND `agent_pricing_rule_version_id` IS NOT NULL AND `rule_public_id_snapshot` IS NOT NULL AND `rule_code_snapshot` IS NOT NULL AND `rule_version` >= 1 AND `rule_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `override_price_irr` >= 0))");
        DB::statement("ALTER TABLE agent_pricing_resolutions ADD CONSTRAINT agent_price_resolution_hashes_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `pricing_profile_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE agent_pricing_resolutions ADD CONSTRAINT agent_price_resolution_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 24 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
    }

};
