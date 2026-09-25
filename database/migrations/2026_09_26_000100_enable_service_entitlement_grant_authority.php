<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const GRANT_OPERATION_TYPES = ['grant_data', 'grant_days', 'grant_data_days'];

    /** @requirement SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function up(): void
    {
        foreach ([
            'administrators', 'audit_logs', 'sales_servers', 'service_operational_authority_capability',
            'service_subscriptions', 'provisioning_operations', 'panel_service_targets',
            'panel_target_capabilities', 'plan_offering_route_selections',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service entitlement grant authority requires the accepted administrator/Service/Panel foundations.');
            }
        }

        $this->ensureTables();
        $this->replaceProvisioningOperationChecks(true);
        $this->createGrantEvidenceGuards();

        foreach ([
            'operation-insert-guard.sql',
            'operation-update-guard.sql',
            'service-update-guard.sql',
            'remote-effect-event-insert-guard.sql',
            'delivery-effect-operation-insert-guard.sql',
            'history-insert-guard.sql',
            'refund-invalidation-guard.sql',
        ] as $file) {
            $this->installSql($file);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('service_entitlement_grant_authorities')
            && DB::table('service_entitlement_grant_authorities')->exists()) {
            throw new RuntimeException('Cannot roll back Service entitlement grant authority while execution evidence exists.');
        }
        if (Schema::hasTable('service_entitlement_grant_items')
            && DB::table('service_entitlement_grant_items')->exists()) {
            throw new RuntimeException('Cannot roll back Service entitlement grant authority while batch item evidence exists.');
        }
        if (Schema::hasTable('service_entitlement_grant_batches')
            && DB::table('service_entitlement_grant_batches')->exists()) {
            throw new RuntimeException('Cannot roll back Service entitlement grant authority while batch evidence exists.');
        }
        if (DB::table('provisioning_operations')->whereIn('operation_type', self::GRANT_OPERATION_TYPES)->exists()) {
            throw new RuntimeException('Cannot roll back Service entitlement grant authority while grant Operations exist.');
        }

        foreach ([
            'DROP TRIGGER IF EXISTS service_entitlement_grant_authorities_delete_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_authorities_update_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_authorities_insert_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_items_delete_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_items_update_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_items_insert_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_batches_delete_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_batches_update_guard',
            'DROP TRIGGER IF EXISTS service_entitlement_grant_batches_insert_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }

        Schema::dropIfExists('service_entitlement_grant_authorities');
        Schema::dropIfExists('service_entitlement_grant_items');
        Schema::dropIfExists('service_entitlement_grant_batches');

        $this->replaceProvisioningOperationChecks(false);
        foreach ([
            'operation-insert-guard.sql',
            'operation-update-guard.sql',
            'service-update-guard.sql',
            'remote-effect-event-insert-guard.sql',
            'delivery-effect-operation-insert-guard.sql',
            'history-insert-guard.sql',
            'refund-invalidation-guard.sql',
        ] as $file) {
            $sql = file_get_contents(database_path('sql/service-reconfiguration-mutation-authority/'.$file));
            if (! is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Prior Service reconfiguration SQL asset is unavailable: '.$file);
            }
            // @phpstan-ignore-next-line argument.type -- verified migration-owned SQL asset.
            DB::unprepared($sql);
        }
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('service_entitlement_grant_batches')) {
            Schema::create('service_entitlement_grant_batches', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique('segb_public_uq');
                $table->char('request_key_hash', 64)->unique('segb_request_uq');
                $table->char('payload_hash', 64);
                $table->string('source_type', 32);
                $table->foreignId('actor_administrator_id');
                $table->foreign('actor_administrator_id', 'segb_admin_fk')->references('id')->on('administrators')->restrictOnDelete();
                $table->foreignId('audit_log_id');
                $table->foreign('audit_log_id', 'segb_audit_fk')->references('id')->on('audit_logs')->restrictOnDelete();
                $table->string('reason_code', 64);
                $table->string('reason', 1000);
                $table->string('selection_mode', 16);
                $table->foreignId('selected_sales_server_id')->nullable();
                $table->foreign('selected_sales_server_id', 'segb_server_fk')->references('id')->on('sales_servers')->restrictOnDelete();
                $table->unsignedBigInteger('data_bytes')->nullable();
                $table->unsignedInteger('duration_days')->nullable();
                $table->boolean('notify_customers')->default(true);
                $table->string('state', 16);
                $table->unsignedInteger('item_count');
                $table->unsignedInteger('queued_count')->default(0);
                $table->unsignedInteger('succeeded_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('needs_review_count')->default(0);
                $table->unsignedInteger('cancelled_count')->default(0);
                $table->string('correlation_id', 64);
                $table->dateTime('expires_at', 6);
                $table->dateTime('items_committed_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->dateTime('completed_at', 6)->nullable();
                $table->index(['state', 'created_at'], 'segb_state_created_idx');
            });
        }

        if (! Schema::hasTable('service_entitlement_grant_items')) {
            Schema::create('service_entitlement_grant_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique('segi_public_uq');
                $table->foreignId('service_entitlement_grant_batch_id');
                $table->foreign('service_entitlement_grant_batch_id', 'segi_batch_fk')
                    ->references('id')->on('service_entitlement_grant_batches')->restrictOnDelete();
                $table->unsignedInteger('position');
                $table->foreignId('service_subscription_id');
                $table->foreign('service_subscription_id', 'segi_service_fk')
                    ->references('id')->on('service_subscriptions')->restrictOnDelete();
                $table->foreignId('service_target_id');
                $table->foreign('service_target_id', 'segi_target_fk')
                    ->references('id')->on('panel_service_targets')->restrictOnDelete();
                $table->unsignedBigInteger('source_mutation_generation');
                $table->unsignedBigInteger('target_remote_identity_generation');
                $table->unsignedBigInteger('target_lifecycle_version');
                $table->char('request_key_hash', 64)->unique('segi_request_uq');
                $table->string('state', 16);
                $table->unsignedInteger('attempt_count')->default(0);
                $table->foreignId('provisioning_operation_id')->nullable()->unique('segi_operation_uq');
                $table->foreign('provisioning_operation_id', 'segi_operation_fk')
                    ->references('id')->on('provisioning_operations')->restrictOnDelete();
                $table->string('result_code', 64)->nullable();
                $table->dateTime('customer_notified_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->unique(['service_entitlement_grant_batch_id', 'position'], 'segi_position_uq');
                $table->unique(['service_entitlement_grant_batch_id', 'service_subscription_id'], 'segi_service_uq');
                $table->index(['service_entitlement_grant_batch_id', 'state', 'position'], 'segi_state_idx');
            });
        }

        if (! Schema::hasTable('service_entitlement_grant_authorities')) {
            Schema::create('service_entitlement_grant_authorities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique('sega_public_uq');
                $table->foreignId('service_entitlement_grant_item_id')->unique('sega_item_uq');
                $table->foreign('service_entitlement_grant_item_id', 'sega_item_fk')
                    ->references('id')->on('service_entitlement_grant_items')->restrictOnDelete();
                $table->foreignId('provisioning_operation_id')->unique('sega_operation_uq');
                $table->foreign('provisioning_operation_id', 'sega_operation_fk')
                    ->references('id')->on('provisioning_operations')->restrictOnDelete();
                $table->foreignId('service_subscription_id');
                $table->foreign('service_subscription_id', 'sega_service_fk')
                    ->references('id')->on('service_subscriptions')->restrictOnDelete();
                $table->foreignId('actor_administrator_id');
                $table->foreign('actor_administrator_id', 'sega_admin_fk')
                    ->references('id')->on('administrators')->restrictOnDelete();
                $table->string('source_type', 32);
                $table->string('reason_code', 64);
                $table->string('reason', 1000);
                $table->string('action', 32);
                $table->unsignedInteger('duration_days')->nullable();
                $table->unsignedBigInteger('data_bytes')->nullable();
                $table->foreignId('quoted_service_target_id');
                $table->foreign('quoted_service_target_id', 'sega_target_fk')
                    ->references('id')->on('panel_service_targets')->restrictOnDelete();
                $table->unsignedBigInteger('quoted_remote_identity_generation');
                $table->unsignedBigInteger('quoted_lifecycle_version');
                $table->char('remote_snapshot_hash', 64)->nullable();
                $table->dateTime('target_expires_at', 6)->nullable();
                $table->unsignedBigInteger('target_data_limit_bytes')->nullable();
                $table->dateTime('targets_resolved_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->index(['service_subscription_id', 'created_at'], 'sega_service_created_idx');
            });
        }

        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_batches',
            'segb_source_chk',
            "BINARY `source_type` IN (BINARY 'admin_grant', BINARY 'campaign_grant')",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_batches',
            'segb_selection_chk',
            "((BINARY `selection_mode` = BINARY 'explicit' AND `selected_sales_server_id` IS NULL) OR (BINARY `selection_mode` = BINARY 'server_all' AND `selected_sales_server_id` IS NOT NULL))",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_batches',
            'segb_package_chk',
            '((`data_bytes` IS NOT NULL AND `data_bytes` > 0) OR (`duration_days` IS NOT NULL AND `duration_days` > 0))',
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_batches',
            'segb_state_chk',
            "`state` IN ('previewed','active','paused','completed','cancelled')",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_batches',
            'segb_count_chk',
            '`item_count` >= 1 AND `queued_count` + `succeeded_count` + `failed_count` + `needs_review_count` + `cancelled_count` <= `item_count`',
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_batches',
            'segb_completion_chk',
            "((`state` = 'completed' AND `succeeded_count` + `failed_count` + `needs_review_count` + `cancelled_count` = `item_count` AND `queued_count` = 0 AND `completed_at` IS NOT NULL) OR (`state` <> 'completed' AND `completed_at` IS NULL))",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_items',
            'segi_state_chk',
            "`state` IN ('pending','queued','succeeded','failed','needs_review','cancelled')",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_items',
            'segi_generation_chk',
            '`target_remote_identity_generation` >= 1 AND `source_mutation_generation` >= 0 AND `target_lifecycle_version` >= 0',
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_items',
            'segi_result_chk',
            "((`state` IN ('pending','cancelled') AND `provisioning_operation_id` IS NULL AND `result_code` IS NULL) OR (`state` = 'queued' AND `provisioning_operation_id` IS NOT NULL AND `result_code` IS NULL) OR (`state` = 'failed' AND `result_code` IS NOT NULL) OR (`state` IN ('succeeded','needs_review') AND `provisioning_operation_id` IS NOT NULL AND `result_code` IS NOT NULL))",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_authorities',
            'sega_action_chk',
            "`action` IN ('grant_data','grant_days','grant_data_days')",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_authorities',
            'sega_package_chk',
            "((`action` = 'grant_days' AND `duration_days` IS NOT NULL AND `data_bytes` IS NULL) OR (`action` = 'grant_data' AND `duration_days` IS NULL AND `data_bytes` IS NOT NULL) OR (`action` = 'grant_data_days' AND `duration_days` IS NOT NULL AND `data_bytes` IS NOT NULL))",
        );
        $this->replaceTableCheckConstraint(
            'service_entitlement_grant_authorities',
            'sega_targets_chk',
            "((`targets_resolved_at` IS NULL AND `remote_snapshot_hash` IS NULL AND `target_expires_at` IS NULL AND `target_data_limit_bytes` IS NULL) OR (`targets_resolved_at` IS NOT NULL AND `remote_snapshot_hash` REGEXP '^[0-9a-f]{64}$' AND ((`action` = 'grant_days' AND `target_expires_at` IS NOT NULL AND `target_data_limit_bytes` IS NULL) OR (`action` = 'grant_data' AND `target_expires_at` IS NULL AND `target_data_limit_bytes` IS NOT NULL) OR (`action` = 'grant_data_days' AND `target_expires_at` IS NOT NULL AND `target_data_limit_bytes` IS NOT NULL))))",
        );
    }

    private function createGrantEvidenceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_batches_insert_guard
BEFORE INSERT ON service_entitlement_grant_batches
FOR EACH ROW
BEGIN
    DECLARE valid_audit INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_audit
    FROM audit_logs audit_row
    WHERE audit_row.id = NEW.audit_log_id
      AND audit_row.action = 'service.operational.entitlement_grant.previewed'
      AND audit_row.actor_type = 'administrator'
      AND audit_row.actor_id = CAST(NEW.actor_administrator_id AS CHAR)
      AND audit_row.target_type = 'service_entitlement_grant_batch'
      AND BINARY audit_row.target_id = BINARY NEW.public_id
      AND BINARY audit_row.request_fingerprint = BINARY NEW.request_key_hash
      AND BINARY audit_row.correlation_id = BINARY NEW.correlation_id
      AND BINARY audit_row.reason_code = BINARY NEW.reason_code
      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.item_count')) AS UNSIGNED) = NEW.item_count
      AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.state')) = BINARY 'previewed'
      AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.payload_hash')) = BINARY NEW.payload_hash;

    IF COALESCE(@app_service_entitlement_grant_batch_authority, '') <> 'service_entitlement_grant_batch_v1'
       OR NOT EXISTS (
           SELECT 1 FROM service_operational_authority_capability capability_row
           WHERE capability_row.id = 1
             AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
       )
       OR valid_audit <> 1
       OR NEW.state <> 'previewed'
       OR NEW.queued_count <> 0
       OR NEW.succeeded_count <> 0
       OR NEW.failed_count <> 0
       OR NEW.needs_review_count <> 0
       OR NEW.cancelled_count <> 0
       OR NEW.items_committed_at IS NOT NULL
       OR NEW.completed_at IS NOT NULL
       OR NEW.expires_at <= NEW.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant batch creation authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_batches_update_guard
BEFORE UPDATE ON service_entitlement_grant_batches
FOR EACH ROW
BEGIN
    DECLARE child_count INT DEFAULT 0;
    DECLARE pending_items INT DEFAULT 0;
    DECLARE queued_items INT DEFAULT 0;
    DECLARE succeeded_items INT DEFAULT 0;
    DECLARE failed_items INT DEFAULT 0;
    DECLARE retryable_failed_items INT DEFAULT 0;
    DECLARE needs_review_items INT DEFAULT 0;
    DECLARE cancelled_items INT DEFAULT 0;

    SELECT
        COUNT(*),
        COALESCE(SUM(item_row.state = 'pending'), 0),
        COALESCE(SUM(item_row.state = 'queued'), 0),
        COALESCE(SUM(item_row.state = 'succeeded'), 0),
        COALESCE(SUM(item_row.state = 'failed'), 0),
        COALESCE(SUM(item_row.state = 'failed' AND item_row.provisioning_operation_id IS NULL), 0),
        COALESCE(SUM(item_row.state = 'needs_review'), 0),
        COALESCE(SUM(item_row.state = 'cancelled'), 0)
      INTO child_count, pending_items, queued_items, succeeded_items, failed_items, retryable_failed_items, needs_review_items, cancelled_items
    FROM service_entitlement_grant_items item_row
    WHERE item_row.service_entitlement_grant_batch_id = OLD.id;

    IF COALESCE(@app_service_entitlement_grant_batch_authority, '') <> 'service_entitlement_grant_batch_v1'
       OR NOT EXISTS (
           SELECT 1 FROM service_operational_authority_capability capability_row
           WHERE capability_row.id = 1
             AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
       )
       OR OLD.id <> COALESCE(@app_service_entitlement_grant_batch_id, 0)
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR BINARY NEW.payload_hash <> BINARY OLD.payload_hash
       OR BINARY NEW.source_type <> BINARY OLD.source_type
       OR NEW.actor_administrator_id <> OLD.actor_administrator_id
       OR NEW.audit_log_id <> OLD.audit_log_id
       OR BINARY NEW.reason_code <> BINARY OLD.reason_code
       OR BINARY NEW.reason <> BINARY OLD.reason
       OR BINARY NEW.selection_mode <> BINARY OLD.selection_mode
       OR NOT (NEW.selected_sales_server_id <=> OLD.selected_sales_server_id)
       OR NOT (NEW.data_bytes <=> OLD.data_bytes)
       OR NOT (NEW.duration_days <=> OLD.duration_days)
       OR NEW.notify_customers <> OLD.notify_customers
       OR NEW.item_count <> OLD.item_count
       OR BINARY NEW.correlation_id <> BINARY OLD.correlation_id
       OR NEW.expires_at <> OLD.expires_at
       OR NEW.created_at <> OLD.created_at
       OR NEW.queued_count <> queued_items
       OR NEW.succeeded_count <> succeeded_items
       OR NEW.failed_count <> failed_items
       OR NEW.needs_review_count <> needs_review_items
       OR NEW.cancelled_count <> cancelled_items THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant batch identity is immutable.';
    END IF;

    IF (OLD.items_committed_at IS NULL AND NEW.items_committed_at IS NOT NULL
            AND child_count = OLD.item_count
            AND pending_items = OLD.item_count
            AND NEW.state = OLD.state)
       OR (OLD.items_committed_at IS NOT NULL AND NEW.items_committed_at = OLD.items_committed_at
            AND ((OLD.state = 'previewed' AND NEW.state IN ('active','cancelled'))
              OR (OLD.state = 'active' AND NEW.state IN ('active','paused','cancelled'))
              OR (OLD.state = 'paused' AND NEW.state IN ('paused','active','cancelled'))
              OR (OLD.state IN ('active','paused') AND NEW.state = 'completed'
                  AND pending_items = 0 AND queued_items = 0 AND retryable_failed_items = 0
                  AND succeeded_items + failed_items + needs_review_items + cancelled_items = OLD.item_count)
              OR (OLD.state = 'cancelled' AND NEW.state IN ('cancelled','completed'))
              OR (OLD.state = 'completed' AND NEW.state = 'completed'))) THEN
        SET child_count = child_count;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant batch transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared("CREATE OR REPLACE TRIGGER service_entitlement_grant_batches_delete_guard BEFORE DELETE ON service_entitlement_grant_batches FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant batch evidence is non-deletable.'; END");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_items_insert_guard
BEFORE INSERT ON service_entitlement_grant_items
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_entitlement_grant_batch_authority, '') <> 'service_entitlement_grant_batch_v1'
       OR NOT EXISTS (
           SELECT 1 FROM service_operational_authority_capability capability_row
           WHERE capability_row.id = 1
             AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
       )
       OR NEW.service_entitlement_grant_batch_id <> COALESCE(@app_service_entitlement_grant_batch_id, 0)
       OR NEW.state <> 'pending'
       OR NEW.attempt_count <> 0
       OR NEW.provisioning_operation_id IS NOT NULL
       OR NEW.result_code IS NOT NULL
       OR NEW.customer_notified_at IS NOT NULL
       OR NOT EXISTS (
           SELECT 1
           FROM service_entitlement_grant_batches batch_row
           INNER JOIN service_subscriptions service_row ON service_row.id = NEW.service_subscription_id
           WHERE batch_row.id = NEW.service_entitlement_grant_batch_id
             AND batch_row.state = 'previewed'
             AND batch_row.items_committed_at IS NULL
             AND service_row.service_target_id = NEW.service_target_id
             AND service_row.mutation_generation = NEW.source_mutation_generation
             AND service_row.remote_identity_generation = NEW.target_remote_identity_generation
             AND service_row.lifecycle_version = NEW.target_lifecycle_version
             AND service_row.lifecycle_state = 'active'
             AND service_row.remote_deleted_at IS NULL
             AND service_row.provisioned_at IS NOT NULL
             AND service_row.remote_service_id IS NOT NULL
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant item creation authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_items_update_guard
BEFORE UPDATE ON service_entitlement_grant_items
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_entitlement_grant_batch_authority, '') <> 'service_entitlement_grant_batch_v1'
       OR NOT EXISTS (
           SELECT 1 FROM service_operational_authority_capability capability_row
           WHERE capability_row.id = 1
             AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
       )
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR NEW.service_entitlement_grant_batch_id <> OLD.service_entitlement_grant_batch_id
       OR NEW.position <> OLD.position
       OR NEW.service_subscription_id <> OLD.service_subscription_id
       OR NEW.service_target_id <> OLD.service_target_id
       OR NEW.source_mutation_generation <> OLD.source_mutation_generation
       OR NEW.target_remote_identity_generation <> OLD.target_remote_identity_generation
       OR NEW.target_lifecycle_version <> OLD.target_lifecycle_version
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR NEW.created_at <> OLD.created_at
       OR NOT EXISTS (
           SELECT 1 FROM service_entitlement_grant_batches batch_row
           WHERE batch_row.id = OLD.service_entitlement_grant_batch_id
             AND batch_row.id = COALESCE(@app_service_entitlement_grant_batch_id, 0)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant item identity is immutable.';
    END IF;

    IF NOT (
        (OLD.state = 'pending' AND NEW.state IN ('pending','queued','failed','cancelled'))
        OR (OLD.state = 'failed' AND OLD.provisioning_operation_id IS NULL AND NEW.state IN ('failed','queued','cancelled'))
        OR (OLD.state = 'queued' AND NEW.state IN ('queued','succeeded','failed','needs_review'))
        OR (OLD.state IN ('succeeded','needs_review','cancelled') AND NEW.state = OLD.state)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant item transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared("CREATE OR REPLACE TRIGGER service_entitlement_grant_items_delete_guard BEFORE DELETE ON service_entitlement_grant_items FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant item evidence is non-deletable.'; END");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_authorities_insert_guard
BEFORE INSERT ON service_entitlement_grant_authorities
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_mutation_authority, '') <> 'service_entitlement_grant_queue_v1'
       OR NOT EXISTS (
           SELECT 1 FROM service_operational_authority_capability capability_row
           WHERE capability_row.id = 1
             AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
       )
       OR NEW.service_entitlement_grant_item_id <> COALESCE(@app_service_entitlement_grant_item_id, 0)
       OR NEW.remote_snapshot_hash IS NOT NULL
       OR NEW.target_expires_at IS NOT NULL
       OR NEW.target_data_limit_bytes IS NOT NULL
       OR NEW.targets_resolved_at IS NOT NULL
       OR NOT EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
           INNER JOIN service_entitlement_grant_items item_row ON item_row.id = NEW.service_entitlement_grant_item_id
           INNER JOIN service_entitlement_grant_batches batch_row ON batch_row.id = item_row.service_entitlement_grant_batch_id
           WHERE operation_row.id = NEW.provisioning_operation_id
             AND operation_row.service_subscription_id = NEW.service_subscription_id
             AND operation_row.operation_type = NEW.action
             AND operation_row.state = 'queued'
             AND operation_row.state_version = 1
             AND item_row.service_subscription_id = NEW.service_subscription_id
             AND item_row.provisioning_operation_id IS NULL
             AND item_row.state IN ('pending','failed')
             AND batch_row.state = 'active'
             AND batch_row.actor_administrator_id = NEW.actor_administrator_id
             AND BINARY batch_row.source_type = BINARY NEW.source_type
             AND BINARY batch_row.reason_code = BINARY NEW.reason_code
             AND BINARY batch_row.reason = BINARY NEW.reason
             AND (batch_row.duration_days <=> NEW.duration_days)
             AND (batch_row.data_bytes <=> NEW.data_bytes)
             AND service_row.mutation_generation = operation_row.operation_generation
             AND service_row.service_target_id = NEW.quoted_service_target_id
             AND service_row.remote_identity_generation = NEW.quoted_remote_identity_generation
             AND service_row.lifecycle_version = NEW.quoted_lifecycle_version
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant authority does not match its active batch item and current Service operation.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_authorities_update_guard
BEFORE UPDATE ON service_entitlement_grant_authorities
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_entitlement_grant_target_authority, '') <> 'service_entitlement_grant_target_v1'
       OR NOT EXISTS (
           SELECT 1 FROM service_operational_authority_capability capability_row
           WHERE capability_row.id = 1
             AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
       )
       OR OLD.provisioning_operation_id <> COALESCE(@app_service_entitlement_grant_operation_id, 0)
       OR BINARY NEW.remote_snapshot_hash <> BINARY COALESCE(@app_service_entitlement_grant_snapshot_hash, '')
       OR NOT (NEW.target_expires_at <=> @app_service_entitlement_grant_target_expires_at)
       OR NOT (NEW.target_data_limit_bytes <=> @app_service_entitlement_grant_target_data_limit)
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR NEW.service_entitlement_grant_item_id <> OLD.service_entitlement_grant_item_id
       OR NEW.provisioning_operation_id <> OLD.provisioning_operation_id
       OR NEW.service_subscription_id <> OLD.service_subscription_id
       OR NEW.actor_administrator_id <> OLD.actor_administrator_id
       OR BINARY NEW.source_type <> BINARY OLD.source_type
       OR BINARY NEW.reason_code <> BINARY OLD.reason_code
       OR BINARY NEW.reason <> BINARY OLD.reason
       OR BINARY NEW.action <> BINARY OLD.action
       OR NOT (NEW.duration_days <=> OLD.duration_days)
       OR NOT (NEW.data_bytes <=> OLD.data_bytes)
       OR NEW.quoted_service_target_id <> OLD.quoted_service_target_id
       OR NEW.quoted_remote_identity_generation <> OLD.quoted_remote_identity_generation
       OR NEW.quoted_lifecycle_version <> OLD.quoted_lifecycle_version
       OR NEW.created_at <> OLD.created_at
       OR OLD.targets_resolved_at IS NOT NULL
       OR OLD.remote_snapshot_hash IS NOT NULL
       OR OLD.target_expires_at IS NOT NULL
       OR OLD.target_data_limit_bytes IS NOT NULL
       OR NEW.targets_resolved_at IS NULL
       OR NEW.remote_snapshot_hash IS NULL
       OR NOT EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
           WHERE operation_row.id = OLD.provisioning_operation_id
             AND operation_row.service_subscription_id = OLD.service_subscription_id
             AND operation_row.state = 'running'
             AND operation_row.remote_effect_started_at IS NULL
             AND service_row.mutation_generation = operation_row.operation_generation
             AND service_row.remote_identity_generation = operation_row.target_remote_identity_generation
             AND service_row.lifecycle_version = operation_row.target_lifecycle_version
             AND service_row.service_target_id = operation_row.service_target_id
             AND service_row.remote_deleted_at IS NULL
             AND service_row.lifecycle_state = 'active'
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant target resolution authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared("CREATE OR REPLACE TRIGGER service_entitlement_grant_authorities_delete_guard BEFORE DELETE ON service_entitlement_grant_authorities FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service entitlement grant authority is non-deletable.'; END");
    }

    private function replaceProvisioningOperationChecks(bool $includeGrantMutations): void
    {
        $types = $includeGrantMutations
            ? "`operation_type` IN ('initial_provision','reset_usage','suspend','activate','delete','rotate_subscription_link','renew','add_data','add_days','add_data_days','grant_data','grant_days','grant_data_days','reconfigure')"
            : "`operation_type` IN ('initial_provision','reset_usage','suspend','activate','delete','rotate_subscription_link','renew','add_data','add_days','add_data_days','reconfigure')";
        $exactTypes = $includeGrantMutations
            ? "BINARY `operation_type` IN (BINARY 'initial_provision', BINARY 'reset_usage', BINARY 'suspend', BINARY 'activate', BINARY 'delete', BINARY 'rotate_subscription_link', BINARY 'renew', BINARY 'add_data', BINARY 'add_days', BINARY 'add_data_days', BINARY 'grant_data', BINARY 'grant_days', BINARY 'grant_data_days', BINARY 'reconfigure')"
            : "BINARY `operation_type` IN (BINARY 'initial_provision', BINARY 'reset_usage', BINARY 'suspend', BINARY 'activate', BINARY 'delete', BINARY 'rotate_subscription_link', BINARY 'renew', BINARY 'add_data', BINARY 'add_days', BINARY 'add_data_days', BINARY 'reconfigure')";

        $this->replaceTableCheckConstraint('provisioning_operations', 'provisioning_operations_type_chk', $types);
        $this->replaceTableCheckConstraint(
            'provisioning_operations',
            'provisioning_operations_provisioning_exact_text_chk',
            <<<SQL
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
SQL,
        );
    }

    private function installSql(string $file): void
    {
        $sql = file_get_contents(database_path('sql/service-entitlement-grant-authority/'.$file));
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Service entitlement grant SQL asset is unavailable: '.$file);
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
