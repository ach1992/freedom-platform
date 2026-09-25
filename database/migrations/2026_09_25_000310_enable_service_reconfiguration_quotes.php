<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-005 BUY-002 PAY-001 PAY-002 AGT-003 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        foreach (['quotes', 'service_reconfiguration_previews', 'service_subscriptions', 'plan_offering_route_selections'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service reconfiguration Quote authority requires the accepted Quote and reconfiguration foundations.');
            }
        }

        $this->ensureColumnsAndBindings();
        $actions = "'purchase','renew','add_data','add_days','add_data_days','reconfigure'";
        $this->replaceCheckConstraint('quotes', 'quotes_action_chk', "`action_snapshot` IN ({$actions})");
        $this->replaceCheckConstraint('quotes', 'quotes_service_package_shape_chk', $this->quoteShapeConstraint());

        foreach ([
            ['agent_pricing_rule_versions', 'agent_price_rule_action_chk', "`action` IS NULL OR `action` IN ({$actions})"],
            ['agent_pricing_resolutions', 'agent_price_resolution_action_chk', "`action` IN ({$actions})"],
            ['quotes', 'quotes_agent_pricing_action_chk', "`agent_pricing_action_snapshot` IS NULL OR `agent_pricing_action_snapshot` IN ({$actions})"],
            ['pricing_rule_versions', 'pricing_rule_versions_action_chk', "`action` IS NULL OR `action` IN ({$actions})"],
            ['pricing_rule_resolutions', 'pricing_rule_resolutions_action_chk', "`action` IN ({$actions})"],
            ['payment_method_eligibility_decisions', 'payment_decision_action_currency_chk', "`action_snapshot` IN ({$actions}) AND `currency_snapshot` = 'IRR' AND `amount_irr_snapshot` >= 0"],
        ] as [$table, $constraint, $definition]) {
            $this->replaceCheckConstraint($table, $constraint, $definition);
        }

        $this->installGuard('quote-insert-guard-v2.sql');
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotes', 'action_snapshot')
            && DB::table('quotes')->where('action_snapshot', 'reconfigure')->exists()) {
            throw new RuntimeException('Cannot roll back Service reconfiguration Quote authority while reconfiguration Quotes exist.');
        }
        foreach (['agent_pricing_rule_versions', 'agent_pricing_resolutions', 'pricing_rule_versions', 'pricing_rule_resolutions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('action', 'reconfigure')->exists()) {
                throw new RuntimeException('Cannot roll back Service reconfiguration Quote authority while reconfiguration pricing evidence exists.');
            }
        }
        if (Schema::hasTable('payment_method_eligibility_decisions')
            && DB::table('payment_method_eligibility_decisions')->where('action_snapshot', 'reconfigure')->exists()) {
            throw new RuntimeException('Cannot roll back Service reconfiguration Quote authority while reconfiguration payment decisions exist.');
        }

        $this->dropConstraintIfExists('quotes', 'quotes_service_package_shape_chk');
        if ($this->constraintExists('quotes', 'quotes_service_reconfiguration_preview_fk')) {
            DB::statement('ALTER TABLE quotes DROP FOREIGN KEY quotes_service_reconfiguration_preview_fk');
        }
        foreach ([
            ['quotes_service_source_route_fk', 'ALTER TABLE quotes DROP FOREIGN KEY quotes_service_source_route_fk'],
            ['quotes_service_target_route_fk', 'ALTER TABLE quotes DROP FOREIGN KEY quotes_service_target_route_fk'],
            ['quotes_service_target_target_fk', 'ALTER TABLE quotes DROP FOREIGN KEY quotes_service_target_target_fk'],
            ['quotes_service_target_profile_fk', 'ALTER TABLE quotes DROP FOREIGN KEY quotes_service_target_profile_fk'],
        ] as [$constraint, $statement]) {
            if ($this->constraintExists('quotes', $constraint)) {
                DB::statement($statement);
            }
        }
        if ($this->indexExists('quotes', 'quotes_reconfiguration_preview_uq')) {
            DB::statement('ALTER TABLE quotes DROP INDEX quotes_reconfiguration_preview_uq');
        }
        foreach ($this->reconfigurationColumns() as $column) {
            if (Schema::hasColumn('quotes', $column)) {
                Schema::table('quotes', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        $baselineActions = "'purchase','renew','add_data','add_days','add_data_days'";
        $this->replaceCheckConstraint('quotes', 'quotes_action_chk', "`action_snapshot` IN ({$baselineActions})");
        $this->replaceCheckConstraint('quotes', 'quotes_service_package_shape_chk', $this->baselineQuoteShapeConstraint());
        foreach ([
            ['agent_pricing_rule_versions', 'agent_price_rule_action_chk', "`action` IS NULL OR `action` IN ({$baselineActions})"],
            ['agent_pricing_resolutions', 'agent_price_resolution_action_chk', "`action` IN ({$baselineActions})"],
            ['quotes', 'quotes_agent_pricing_action_chk', "`agent_pricing_action_snapshot` IS NULL OR `agent_pricing_action_snapshot` IN ({$baselineActions})"],
            ['pricing_rule_versions', 'pricing_rule_versions_action_chk', "`action` IS NULL OR `action` IN ({$baselineActions})"],
            ['pricing_rule_resolutions', 'pricing_rule_resolutions_action_chk', "`action` IN ({$baselineActions})"],
            ['payment_method_eligibility_decisions', 'payment_decision_action_currency_chk', "`action_snapshot` IN ({$baselineActions}) AND `currency_snapshot` = 'IRR' AND `amount_irr_snapshot` >= 0"],
        ] as [$table, $constraint, $definition]) {
            $this->replaceCheckConstraint($table, $constraint, $definition);
        }
        $this->installGuard('quote-insert-guard-v1.sql');
    }

    private function ensureColumnsAndBindings(): void
    {
        if (! Schema::hasColumn('quotes', 'service_reconfiguration_preview_id')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->unsignedBigInteger('service_mutation_generation_snapshot')->nullable()->after('service_lifecycle_version_snapshot');
                $table->foreignId('service_reconfiguration_preview_id')->nullable()->after('service_required_capability_code_snapshot');
                $table->ulid('service_reconfiguration_preview_public_id')->nullable()->after('service_reconfiguration_preview_id');
                $table->unsignedBigInteger('service_source_route_selection_id_snapshot')->nullable()->after('service_reconfiguration_preview_public_id');
                $table->unsignedBigInteger('service_target_route_selection_id_snapshot')->nullable()->after('service_source_route_selection_id_snapshot');
                $table->unsignedBigInteger('service_target_service_target_id_snapshot')->nullable()->after('service_target_route_selection_id_snapshot');
                $table->unsignedBigInteger('service_target_protocol_profile_id_snapshot')->nullable()->after('service_target_service_target_id_snapshot');
            });
        }
        foreach ($this->reconfigurationColumns() as $column) {
            if (! Schema::hasColumn('quotes', $column)) {
                throw new RuntimeException('Service reconfiguration Quote migration is partially applied and cannot be safely normalized.');
            }
        }
        if (! $this->constraintExists('quotes', 'quotes_service_reconfiguration_preview_fk')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->foreign('service_reconfiguration_preview_id', 'quotes_service_reconfiguration_preview_fk')
                    ->references('id')->on('service_reconfiguration_previews')->restrictOnDelete();
                $table->foreign('service_source_route_selection_id_snapshot', 'quotes_service_source_route_fk')
                    ->references('id')->on('plan_offering_route_selections')->restrictOnDelete();
                $table->foreign('service_target_route_selection_id_snapshot', 'quotes_service_target_route_fk')
                    ->references('id')->on('plan_offering_route_selections')->restrictOnDelete();
                $table->foreign('service_target_service_target_id_snapshot', 'quotes_service_target_target_fk')
                    ->references('id')->on('panel_service_targets')->restrictOnDelete();
                $table->foreign('service_target_protocol_profile_id_snapshot', 'quotes_service_target_profile_fk')
                    ->references('id')->on('panel_protocol_profiles')->restrictOnDelete();
            });
        }
        if (! $this->indexExists('quotes', 'quotes_reconfiguration_preview_uq')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->unique('service_reconfiguration_preview_id', 'quotes_reconfiguration_preview_uq');
            });
        }
    }

    /** @return list<string> */
    private function reconfigurationColumns(): array
    {
        return [
            'service_mutation_generation_snapshot',
            'service_reconfiguration_preview_id',
            'service_reconfiguration_preview_public_id',
            'service_source_route_selection_id_snapshot',
            'service_target_route_selection_id_snapshot',
            'service_target_service_target_id_snapshot',
            'service_target_protocol_profile_id_snapshot',
        ];
    }

    private function quoteShapeConstraint(): string
    {
        return <<<'SQL'
(
    (`action_snapshot` = 'purchase'
        AND `service_subscription_id` IS NULL
        AND `service_subscription_public_id` IS NULL
        AND `service_target_id_snapshot` IS NULL
        AND `service_remote_identity_generation_snapshot` IS NULL
        AND `service_lifecycle_version_snapshot` IS NULL
        AND `service_mutation_generation_snapshot` IS NULL
        AND `service_package_id_snapshot` IS NULL
        AND `service_package_code_snapshot` IS NULL
        AND `service_package_type_snapshot` IS NULL
        AND `service_package_duration_days_snapshot` IS NULL
        AND `service_package_data_bytes_snapshot` IS NULL
        AND `service_required_capability_code_snapshot` IS NULL
        AND `service_reconfiguration_preview_id` IS NULL
        AND `service_reconfiguration_preview_public_id` IS NULL
        AND `service_source_route_selection_id_snapshot` IS NULL
        AND `service_target_route_selection_id_snapshot` IS NULL
        AND `service_target_service_target_id_snapshot` IS NULL
        AND `service_target_protocol_profile_id_snapshot` IS NULL)
    OR
    (`action_snapshot` IN ('renew','add_data','add_days','add_data_days')
        AND `service_subscription_id` IS NOT NULL
        AND `service_subscription_public_id` IS NOT NULL
        AND `service_target_id_snapshot` IS NOT NULL
        AND `service_remote_identity_generation_snapshot` >= 1
        AND `service_lifecycle_version_snapshot` >= 0
        AND `service_mutation_generation_snapshot` IS NULL
        AND `service_package_id_snapshot` IS NOT NULL
        AND `service_package_code_snapshot` IS NOT NULL
        AND `service_package_type_snapshot` IS NOT NULL
        AND `service_reconfiguration_preview_id` IS NULL
        AND `service_reconfiguration_preview_public_id` IS NULL
        AND `service_source_route_selection_id_snapshot` IS NULL
        AND `service_target_route_selection_id_snapshot` IS NULL
        AND `service_target_service_target_id_snapshot` IS NULL
        AND `service_target_protocol_profile_id_snapshot` IS NULL
        AND (
            (`action_snapshot` = 'renew' AND `service_package_type_snapshot` = 'renewal' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NULL)
            OR (`action_snapshot` = 'add_data' AND `service_package_type_snapshot` = 'add_data' AND `service_package_duration_days_snapshot` IS NULL AND `service_package_data_bytes_snapshot` IS NOT NULL)
            OR (`action_snapshot` = 'add_days' AND `service_package_type_snapshot` = 'add_days' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NULL)
            OR (`action_snapshot` = 'add_data_days' AND `service_package_type_snapshot` = 'add_data_days' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NOT NULL)
        ))
    OR
    (`action_snapshot` = 'reconfigure'
        AND `service_subscription_id` IS NOT NULL
        AND `service_subscription_public_id` IS NOT NULL
        AND `service_target_id_snapshot` IS NOT NULL
        AND `service_remote_identity_generation_snapshot` >= 1
        AND `service_lifecycle_version_snapshot` >= 0
        AND `service_mutation_generation_snapshot` >= 0
        AND `service_package_id_snapshot` IS NULL
        AND `service_package_code_snapshot` IS NULL
        AND `service_package_type_snapshot` IS NULL
        AND `service_package_duration_days_snapshot` IS NULL
        AND `service_package_data_bytes_snapshot` IS NULL
        AND `service_required_capability_code_snapshot` IS NULL
        AND `service_reconfiguration_preview_id` IS NOT NULL
        AND `service_reconfiguration_preview_public_id` IS NOT NULL
        AND `service_target_route_selection_id_snapshot` IS NOT NULL
        AND `service_target_service_target_id_snapshot` IS NOT NULL
        AND `service_target_protocol_profile_id_snapshot` IS NOT NULL)
)
SQL;
    }

    private function baselineQuoteShapeConstraint(): string
    {
        return <<<'SQL'
(
    (`action_snapshot` = 'purchase'
        AND `service_subscription_id` IS NULL
        AND `service_subscription_public_id` IS NULL
        AND `service_target_id_snapshot` IS NULL
        AND `service_remote_identity_generation_snapshot` IS NULL
        AND `service_lifecycle_version_snapshot` IS NULL
        AND `service_package_id_snapshot` IS NULL
        AND `service_package_code_snapshot` IS NULL
        AND `service_package_type_snapshot` IS NULL
        AND `service_package_duration_days_snapshot` IS NULL
        AND `service_package_data_bytes_snapshot` IS NULL
        AND `service_required_capability_code_snapshot` IS NULL)
    OR
    (`action_snapshot` <> 'purchase'
        AND `service_subscription_id` IS NOT NULL
        AND `service_subscription_public_id` IS NOT NULL
        AND `service_target_id_snapshot` IS NOT NULL
        AND `service_remote_identity_generation_snapshot` >= 1
        AND `service_lifecycle_version_snapshot` >= 0
        AND `service_package_id_snapshot` IS NOT NULL
        AND `service_package_code_snapshot` IS NOT NULL
        AND `service_package_type_snapshot` IS NOT NULL
        AND (
            (`action_snapshot` = 'renew' AND `service_package_type_snapshot` = 'renewal' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NULL)
            OR (`action_snapshot` = 'add_data' AND `service_package_type_snapshot` = 'add_data' AND `service_package_duration_days_snapshot` IS NULL AND `service_package_data_bytes_snapshot` IS NOT NULL)
            OR (`action_snapshot` = 'add_days' AND `service_package_type_snapshot` = 'add_days' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NULL)
            OR (`action_snapshot` = 'add_data_days' AND `service_package_type_snapshot` = 'add_data_days' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NOT NULL)
        ))
)
SQL;
    }

    private function replaceCheckConstraint(string $table, string $constraint, string $definition): void
    {
        $this->dropConstraintIfExists($table, $constraint);
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$definition})");
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index],
        );

        return $row !== null && (int) $row->aggregate > 0;
    }

    private function installGuard(string $file): void
    {
        $path = database_path('sql/service-reconfiguration-quote-authority/'.$file);
        $sql = file_get_contents($path);
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Service reconfiguration Quote guard SQL is unavailable: '.$file);
        }
        DB::connection()->getPdo()->exec($sql);
    }
};
