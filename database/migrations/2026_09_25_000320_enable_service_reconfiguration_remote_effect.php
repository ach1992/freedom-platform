<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-005 BUY-002 PAY-002 PRV-002 PRV-003 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        foreach ([
            'provisioning_operations', 'service_subscriptions', 'service_reconfiguration_previews', 'quotes',
            'orders', 'order_items', 'purchase_settlements', 'payment_intents', 'plan_offering_route_selections',
            'panel_capacity_reservations', 'panel_service_targets', 'panel_protocol_profiles',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service reconfiguration remote-effect authority requires the accepted Service/Quote/Panel foundations.');
            }
        }

        if (! Schema::hasTable('service_reconfiguration_authorities')) {
            Schema::create('service_reconfiguration_authorities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique('sra_public_uq');
                $table->foreignId('provisioning_operation_id')->unique('sra_operation_uq');
                $table->foreign('provisioning_operation_id', 'sra_operation_fk')->references('id')->on('provisioning_operations')->restrictOnDelete();
                $table->foreignId('service_subscription_id');
                $table->foreign('service_subscription_id', 'sra_service_fk')->references('id')->on('service_subscriptions')->restrictOnDelete();
                $table->foreignId('source_quote_id')->unique('sra_quote_uq');
                $table->foreign('source_quote_id', 'sra_quote_fk')->references('id')->on('quotes')->restrictOnDelete();
                $table->foreignId('purchase_order_id')->unique('sra_order_uq');
                $table->foreign('purchase_order_id', 'sra_order_fk')->references('id')->on('orders')->restrictOnDelete();
                $table->foreignId('purchase_order_item_id')->unique('sra_item_uq');
                $table->foreign('purchase_order_item_id', 'sra_item_fk')->references('id')->on('order_items')->restrictOnDelete();
                $table->foreignId('purchase_settlement_id')->unique('sra_settlement_uq');
                $table->foreign('purchase_settlement_id', 'sra_settlement_fk')->references('id')->on('purchase_settlements')->restrictOnDelete();
                $table->foreignId('payment_intent_id')->unique('sra_intent_uq');
                $table->foreign('payment_intent_id', 'sra_intent_fk')->references('id')->on('payment_intents')->restrictOnDelete();
                $table->foreignId('reconfiguration_preview_id')->unique('sra_preview_uq');
                $table->foreign('reconfiguration_preview_id', 'sra_preview_fk')->references('id')->on('service_reconfiguration_previews')->restrictOnDelete();
                $table->foreignId('target_plan_offering_id');
                $table->foreign('target_plan_offering_id', 'sra_target_offering_fk')->references('id')->on('plan_offerings')->restrictOnDelete();
                $table->string('target_plan_offering_code', 64);
                $table->unsignedBigInteger('target_plan_offering_version');
                $table->unsignedBigInteger('source_route_selection_id')->nullable();
                $table->foreign('source_route_selection_id', 'sra_source_route_fk')->references('id')->on('plan_offering_route_selections')->restrictOnDelete();
                $table->foreignId('source_service_target_id');
                $table->foreign('source_service_target_id', 'sra_source_target_fk')->references('id')->on('panel_service_targets')->restrictOnDelete();
                $table->unsignedBigInteger('target_route_selection_id')->unique('sra_target_route_uq');
                $table->foreign('target_route_selection_id', 'sra_target_route_fk')->references('id')->on('plan_offering_route_selections')->restrictOnDelete();
                $table->foreignId('target_service_target_id');
                $table->foreign('target_service_target_id', 'sra_target_target_fk')->references('id')->on('panel_service_targets')->restrictOnDelete();
                $table->unsignedBigInteger('target_service_target_version');
                $table->foreignId('target_protocol_profile_id');
                $table->foreign('target_protocol_profile_id', 'sra_target_profile_fk')->references('id')->on('panel_protocol_profiles')->restrictOnDelete();
                $table->unsignedBigInteger('target_protocol_profile_version');
                $table->unsignedBigInteger('target_capacity_reservation_id')->unique('sra_target_capacity_uq');
                $table->foreign('target_capacity_reservation_id', 'sra_target_capacity_fk')->references('id')->on('panel_capacity_reservations')->restrictOnDelete();
                $table->string('target_capacity_reservation_key', 128);
                $table->string('target_reference', 191);
                $table->string('target_protocol_profile_code', 128);
                $table->unsignedBigInteger('quoted_remote_identity_generation');
                $table->unsignedBigInteger('quoted_lifecycle_version');
                $table->unsignedBigInteger('quoted_mutation_generation');
                $table->string('result_remote_service_id', 512)->nullable();
                $table->char('remote_result_snapshot_hash', 64)->nullable();
                $table->dateTime('result_recorded_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->index(['service_subscription_id', 'created_at'], 'sra_service_created_idx');
            });
        }

        $this->replaceTableCheckConstraint(
            'service_reconfiguration_authorities',
            'sra_generation_chk',
            '`target_service_target_version` >= 1 AND `target_protocol_profile_version` >= 1 AND `quoted_remote_identity_generation` >= 1 AND `quoted_lifecycle_version` >= 0 AND `quoted_mutation_generation` >= 0',
        );
        $this->replaceTableCheckConstraint(
            'service_reconfiguration_authorities',
            'sra_target_code_chk',
            "`target_plan_offering_code` REGEXP '^[a-z0-9_.-]{2,64}$' AND `target_reference` REGEXP '^[A-Za-z0-9_.:-]{1,191}$' AND `target_protocol_profile_code` REGEXP '^[a-z0-9_.-]{1,128}$'",
        );
        $this->replaceTableCheckConstraint(
            'service_reconfiguration_authorities',
            'sra_result_shape_chk',
            "((`result_recorded_at` IS NULL AND `result_remote_service_id` IS NULL AND `remote_result_snapshot_hash` IS NULL) OR (`result_recorded_at` IS NOT NULL AND `result_remote_service_id` IS NOT NULL AND `remote_result_snapshot_hash` REGEXP '^[0-9a-f]{64}$'))",
        );

        $this->replaceProvisioningOperationChecks(true);
        $this->createAuthorityGuards();
        foreach ([
            'operation-insert-guard.sql', 'operation-update-guard.sql', 'service-update-guard.sql',
            'remote-effect-event-insert-guard.sql', 'delivery-effect-operation-insert-guard.sql',
            'history-insert-guard.sql', 'refund-invalidation-guard.sql',
        ] as $file) {
            $this->installSql($file);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_reconfiguration_authorities') && DB::table('service_reconfiguration_authorities')->exists()) {
            throw new RuntimeException('Cannot roll back Service reconfiguration remote-effect authority while execution evidence exists.');
        }
        if (DB::table('provisioning_operations')->where('operation_type', 'reconfigure')->exists()) {
            throw new RuntimeException('Cannot roll back Service reconfiguration remote-effect authority while reconfiguration operations exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS service_reconfiguration_authorities_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_reconfiguration_authorities_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_reconfiguration_authorities_insert_guard');
        Schema::dropIfExists('service_reconfiguration_authorities');

        $this->replaceProvisioningOperationChecks(false);
        foreach ([
            'operation-insert-guard.sql', 'operation-update-guard.sql', 'service-update-guard.sql',
            'remote-effect-event-insert-guard.sql', 'delivery-effect-operation-insert-guard.sql',
            'history-insert-guard.sql', 'refund-invalidation-guard.sql',
        ] as $file) {
            $sql = file_get_contents(database_path('sql/service-paid-mutation-authority/'.$file));
            if (! is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Paid Service mutation rollback SQL asset is unavailable: '.$file);
            }
            // @phpstan-ignore-next-line argument.type -- verified migration-owned SQL asset.
            DB::unprepared($sql);
        }
    }

    private function replaceProvisioningOperationChecks(bool $includeReconfiguration): void
    {
        $types = $includeReconfiguration
            ? "`operation_type` IN ('initial_provision','reset_usage','suspend','activate','delete','rotate_subscription_link','renew','add_data','add_days','add_data_days','reconfigure')"
            : "`operation_type` IN ('initial_provision','reset_usage','suspend','activate','delete','rotate_subscription_link','renew','add_data','add_days','add_data_days')";
        $exactTypes = $includeReconfiguration
            ? "BINARY `operation_type` IN (BINARY 'initial_provision', BINARY 'reset_usage', BINARY 'suspend', BINARY 'activate', BINARY 'delete', BINARY 'rotate_subscription_link', BINARY 'renew', BINARY 'add_data', BINARY 'add_days', BINARY 'add_data_days', BINARY 'reconfigure')"
            : "BINARY `operation_type` IN (BINARY 'initial_provision', BINARY 'reset_usage', BINARY 'suspend', BINARY 'activate', BINARY 'delete', BINARY 'rotate_subscription_link', BINARY 'renew', BINARY 'add_data', BINARY 'add_days', BINARY 'add_data_days')";

        $this->replaceTableCheckConstraint('provisioning_operations', 'provisioning_operations_type_chk', $types);
        $this->replaceTableCheckConstraint('provisioning_operations', 'provisioning_operations_provisioning_exact_text_chk', <<<SQL
(
    COLLATION(`operation_key`) <> 'utf8mb4_bin'
    OR (
        {$exactTypes}
        AND BINARY `operation_key` = BINARY RTRIM(`operation_key`)
        AND BINARY `state` IN (
            BINARY 'queued', BINARY 'running', BINARY 'uncertain_remote_result', BINARY 'retry_scheduled',
            BINARY 'succeeded', BINARY 'failed_final', BINARY 'needs_review', BINARY 'compensating', BINARY 'compensated'
        )
        AND BINARY `correlation_id` = BINARY RTRIM(`correlation_id`)
    )
)
SQL);
    }

    private function createAuthorityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_reconfiguration_authorities_insert_guard
BEFORE INSERT ON service_reconfiguration_authorities
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_mutation_authority, '') <> 'service_paid_mutation_queue_v1'
       OR NEW.result_remote_service_id IS NOT NULL
       OR NEW.remote_result_snapshot_hash IS NOT NULL
       OR NEW.result_recorded_at IS NOT NULL
       OR NOT EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
           INNER JOIN orders order_row ON order_row.id = NEW.purchase_order_id
           INNER JOIN order_items item_row ON item_row.id = NEW.purchase_order_item_id AND item_row.order_id = order_row.id
           INNER JOIN purchase_settlements settlement_row ON settlement_row.id = NEW.purchase_settlement_id
           INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
           INNER JOIN quotes quote_row ON quote_row.id = NEW.source_quote_id
           INNER JOIN service_reconfiguration_previews preview_row ON preview_row.id = NEW.reconfiguration_preview_id
           INNER JOIN plan_offerings target_offering ON target_offering.id = NEW.target_plan_offering_id
           INNER JOIN plan_offering_route_selections target_selection ON target_selection.id = NEW.target_route_selection_id
           INNER JOIN panel_capacity_reservations target_reservation ON target_reservation.id = NEW.target_capacity_reservation_id
           INNER JOIN panel_service_targets source_target ON source_target.id = NEW.source_service_target_id
           INNER JOIN panel_service_targets target_target ON target_target.id = NEW.target_service_target_id
           INNER JOIN panel_protocol_profiles target_profile ON target_profile.id = NEW.target_protocol_profile_id
           LEFT JOIN plan_offering_route_selections source_selection ON source_selection.id = NEW.source_route_selection_id
           LEFT JOIN panel_capacity_reservations source_reservation ON source_reservation.id = source_selection.capacity_reservation_id
           WHERE operation_row.id = NEW.provisioning_operation_id
             AND operation_row.operation_type = 'reconfigure'
             AND operation_row.state = 'queued'
             AND operation_row.state_version = 1
             AND operation_row.service_subscription_id = NEW.service_subscription_id
             AND operation_row.order_id = order_row.id
             AND operation_row.order_item_id = item_row.id
             AND operation_row.operation_generation = service_row.mutation_generation
             AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
             AND operation_row.target_lifecycle_version = service_row.lifecycle_version
             AND operation_row.service_target_id = NEW.source_service_target_id
             AND BINARY operation_row.remote_service_id = BINARY service_row.remote_service_id
             AND order_row.purchase_settlement_id = settlement_row.id
             AND order_row.payment_intent_id = intent_row.id
             AND order_row.source_quote_id = quote_row.id
             AND order_row.state = 'paid'
             AND order_row.state_version = 1
             AND item_row.source_quote_id = quote_row.id
             AND settlement_row.payment_intent_id = intent_row.id
             AND settlement_row.source_quote_id = quote_row.id
             AND intent_row.purpose = 'purchase'
             AND intent_row.state = 'captured'
             AND intent_row.captured_at IS NOT NULL
             AND quote_row.action_snapshot = 'reconfigure'
             AND quote_row.plan_offering_id = NEW.target_plan_offering_id
             AND BINARY quote_row.offering_code_snapshot = BINARY NEW.target_plan_offering_code
             AND quote_row.offering_version = NEW.target_plan_offering_version
             AND quote_row.service_subscription_id = service_row.id
             AND quote_row.service_reconfiguration_preview_id = preview_row.id
             AND quote_row.service_reconfiguration_preview_id = NEW.reconfiguration_preview_id
             AND (quote_row.service_source_route_selection_id_snapshot <=> NEW.source_route_selection_id)
             AND quote_row.service_target_id_snapshot = NEW.source_service_target_id
             AND quote_row.service_remote_identity_generation_snapshot = NEW.quoted_remote_identity_generation
             AND quote_row.service_lifecycle_version_snapshot = NEW.quoted_lifecycle_version
             AND quote_row.service_mutation_generation_snapshot = NEW.quoted_mutation_generation
             AND quote_row.service_target_route_selection_id_snapshot = NEW.target_route_selection_id
             AND quote_row.service_target_service_target_id_snapshot = NEW.target_service_target_id
             AND quote_row.service_target_service_target_version_snapshot = NEW.target_service_target_version
             AND quote_row.service_target_protocol_profile_id_snapshot = NEW.target_protocol_profile_id
             AND quote_row.service_target_protocol_profile_version_snapshot = NEW.target_protocol_profile_version
             AND preview_row.service_subscription_id = service_row.id
             AND preview_row.target_plan_offering_id = NEW.target_plan_offering_id
             AND target_offering.state = 'active'
             AND target_offering.visibility = 'visible'
             AND target_offering.version = NEW.target_plan_offering_version
             AND BINARY target_offering.code = BINARY NEW.target_plan_offering_code
             AND preview_row.actor_user_id = service_row.user_id
             AND (preview_row.source_route_selection_id <=> NEW.source_route_selection_id)
             AND preview_row.source_service_target_id = NEW.source_service_target_id
             AND preview_row.source_remote_identity_generation = NEW.quoted_remote_identity_generation
             AND preview_row.source_lifecycle_version = NEW.quoted_lifecycle_version
             AND preview_row.source_mutation_generation = NEW.quoted_mutation_generation
             AND preview_row.target_route_selection_id = NEW.target_route_selection_id
             AND preview_row.target_service_target_id = NEW.target_service_target_id
             AND preview_row.target_service_target_version = NEW.target_service_target_version
             AND preview_row.target_protocol_profile_id = NEW.target_protocol_profile_id
             AND preview_row.target_protocol_profile_version = NEW.target_protocol_profile_version
             AND preview_row.target_capacity_reservation_id = NEW.target_capacity_reservation_id
             AND BINARY preview_row.target_capacity_reservation_key = BINARY NEW.target_capacity_reservation_key
             AND service_row.user_id = order_row.user_id
             AND (service_row.route_selection_id <=> NEW.source_route_selection_id)
             AND service_row.service_target_id = NEW.source_service_target_id
             AND service_row.remote_identity_generation = NEW.quoted_remote_identity_generation
             AND service_row.lifecycle_version = NEW.quoted_lifecycle_version
             AND service_row.mutation_generation = NEW.quoted_mutation_generation + 1
             AND service_row.lifecycle_state IN ('active','suspended')
             AND service_row.remote_deleted_at IS NULL
             AND target_selection.plan_offering_id = preview_row.target_plan_offering_id
             AND target_selection.selected_service_target_id = NEW.target_service_target_id
             AND target_selection.panel_protocol_profile_id = NEW.target_protocol_profile_id
             AND target_selection.capacity_reservation_id = NEW.target_capacity_reservation_id
             AND target_reservation.state = 'held'
             AND target_reservation.units = 1
             AND BINARY target_reservation.reservation_key = BINARY NEW.target_capacity_reservation_key
             AND source_target.panel_connection_id = target_target.panel_connection_id
             AND target_target.state = 'active'
             AND target_target.capability_status = 'verified'
             AND target_target.version = NEW.target_service_target_version
             AND target_profile.state = 'active'
             AND target_profile.version = NEW.target_protocol_profile_version
             AND (NEW.source_route_selection_id IS NULL OR (source_reservation.state = 'committed' AND source_reservation.units = 1))
             AND NOT EXISTS (
                 SELECT 1 FROM provisioning_financial_invalidations invalidation
                 WHERE invalidation.purchase_settlement_id = settlement_row.id
                   AND invalidation.payment_intent_id = intent_row.id
             )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration authority does not match captured purchase, destination, and current Service authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_reconfiguration_authorities_update_guard
BEFORE UPDATE ON service_reconfiguration_authorities
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_reconfiguration_result_authority, '') <> 'service_reconfiguration_result_v1'
       OR OLD.provisioning_operation_id <> COALESCE(@app_service_reconfiguration_operation_id, 0)
       OR BINARY NEW.result_remote_service_id <> BINARY COALESCE(@app_service_reconfiguration_result_remote_id, '')
       OR BINARY NEW.remote_result_snapshot_hash <> BINARY COALESCE(@app_service_reconfiguration_result_snapshot_hash, '')
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR NEW.provisioning_operation_id <> OLD.provisioning_operation_id
       OR NEW.service_subscription_id <> OLD.service_subscription_id
       OR NEW.source_quote_id <> OLD.source_quote_id
       OR NEW.purchase_order_id <> OLD.purchase_order_id
       OR NEW.purchase_order_item_id <> OLD.purchase_order_item_id
       OR NEW.purchase_settlement_id <> OLD.purchase_settlement_id
       OR NEW.payment_intent_id <> OLD.payment_intent_id
       OR NEW.reconfiguration_preview_id <> OLD.reconfiguration_preview_id
       OR NEW.target_plan_offering_id <> OLD.target_plan_offering_id
       OR BINARY NEW.target_plan_offering_code <> BINARY OLD.target_plan_offering_code
       OR NEW.target_plan_offering_version <> OLD.target_plan_offering_version
       OR NOT (NEW.source_route_selection_id <=> OLD.source_route_selection_id)
       OR NEW.source_service_target_id <> OLD.source_service_target_id
       OR NEW.target_route_selection_id <> OLD.target_route_selection_id
       OR NEW.target_service_target_id <> OLD.target_service_target_id
       OR NEW.target_service_target_version <> OLD.target_service_target_version
       OR NEW.target_protocol_profile_id <> OLD.target_protocol_profile_id
       OR NEW.target_protocol_profile_version <> OLD.target_protocol_profile_version
       OR NEW.target_capacity_reservation_id <> OLD.target_capacity_reservation_id
       OR BINARY NEW.target_capacity_reservation_key <> BINARY OLD.target_capacity_reservation_key
       OR BINARY NEW.target_reference <> BINARY OLD.target_reference
       OR BINARY NEW.target_protocol_profile_code <> BINARY OLD.target_protocol_profile_code
       OR NEW.quoted_remote_identity_generation <> OLD.quoted_remote_identity_generation
       OR NEW.quoted_lifecycle_version <> OLD.quoted_lifecycle_version
       OR NEW.quoted_mutation_generation <> OLD.quoted_mutation_generation
       OR NEW.created_at <> OLD.created_at
       OR OLD.result_recorded_at IS NOT NULL
       OR OLD.result_remote_service_id IS NOT NULL
       OR OLD.remote_result_snapshot_hash IS NOT NULL
       OR NEW.result_recorded_at IS NULL
       OR NEW.result_remote_service_id IS NULL
       OR NEW.remote_result_snapshot_hash IS NULL
       OR NOT EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
           INNER JOIN panel_capacity_reservations target_reservation ON target_reservation.id = OLD.target_capacity_reservation_id
           INNER JOIN plan_offerings target_offering ON target_offering.id = OLD.target_plan_offering_id
           INNER JOIN panel_service_targets target_target ON target_target.id = OLD.target_service_target_id
           INNER JOIN panel_protocol_profiles target_profile ON target_profile.id = OLD.target_protocol_profile_id
           WHERE operation_row.id = OLD.provisioning_operation_id
             AND operation_row.service_subscription_id = OLD.service_subscription_id
             AND operation_row.operation_type = 'reconfigure'
             AND operation_row.state = 'running'
             AND operation_row.remote_effect_started_at IS NOT NULL
             AND operation_row.remote_effect_completed_at IS NULL
             AND operation_row.operation_generation = service_row.mutation_generation
             AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
             AND operation_row.target_lifecycle_version = service_row.lifecycle_version
             AND service_row.service_target_id = OLD.source_service_target_id
             AND (service_row.route_selection_id <=> OLD.source_route_selection_id)
             AND service_row.remote_deleted_at IS NULL
             AND service_row.lifecycle_state IN ('active','suspended')
             AND target_reservation.state = 'held'
             AND target_reservation.units = 1
             AND target_offering.state = 'active'
             AND target_offering.visibility = 'visible'
             AND target_offering.version = OLD.target_plan_offering_version
             AND BINARY target_offering.code = BINARY OLD.target_plan_offering_code
             AND target_target.state = 'active'
             AND target_target.capability_status = 'verified'
             AND target_target.version = OLD.target_service_target_version
             AND target_profile.state = 'active'
             AND target_profile.version = OLD.target_protocol_profile_version
             AND NOT EXISTS (
                 SELECT 1 FROM provisioning_financial_invalidations invalidation
                 WHERE invalidation.purchase_settlement_id = OLD.purchase_settlement_id
                   AND invalidation.payment_intent_id = OLD.payment_intent_id
             )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration remote result evidence authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_reconfiguration_authorities_delete_guard
BEFORE DELETE ON service_reconfiguration_authorities
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconfiguration authority is non-deletable.';
END
SQL);
    }

    private function installSql(string $file): void
    {
        $sql = file_get_contents(database_path('sql/service-reconfiguration-mutation-authority/'.$file));
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Service reconfiguration remote-effect SQL asset is unavailable: '.$file);
        }
        // @phpstan-ignore-next-line argument.type -- verified migration-owned SQL asset.
        DB::unprepared($sql);
    }

    private function replaceTableCheckConstraint(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$definition})");
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
