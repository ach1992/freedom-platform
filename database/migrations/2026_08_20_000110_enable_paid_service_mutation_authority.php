<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PAID_OPERATION_TYPES = ['renew', 'add_data', 'add_days', 'add_data_days'];

    /** @requirement BUY-002 PAY-002 SVC-003 SVC-004 PRV-002 PRV-003 DAT-003 SEC-002 QUA-004 */
    public function up(): void
    {
        // MariaDB DDL commits per statement. Fence the new paid operation types first so
        // interrupted/re-entered migration work can never expose a partially composed authority.
        $this->installPaidUpgradeFence();
        $this->ensureAuthorityTable();

        $this->replaceCheckConstraint(
            'service_paid_mutation_action_chk',
            "`action` IN ('renew','add_data','add_days','add_data_days')",
        );
        $this->replaceCheckConstraint('service_paid_mutation_package_shape_chk', <<<'SQL'
(
    (`action` IN ('renew','add_days') AND `duration_days` IS NOT NULL AND `data_bytes` IS NULL)
    OR (`action` = 'add_data' AND `duration_days` IS NULL AND `data_bytes` IS NOT NULL)
    OR (`action` = 'add_data_days' AND `duration_days` IS NOT NULL AND `data_bytes` IS NOT NULL)
)
SQL);
        $this->replaceCheckConstraint('service_paid_mutation_targets_shape_chk', <<<'SQL'
(
    (`targets_resolved_at` IS NULL AND `remote_snapshot_hash` IS NULL AND `target_expires_at` IS NULL AND `target_data_limit_bytes` IS NULL)
    OR (`targets_resolved_at` IS NOT NULL AND `remote_snapshot_hash` REGEXP '^[0-9a-f]{64}$'
        AND ((`action` IN ('renew','add_days') AND `target_expires_at` IS NOT NULL AND `target_data_limit_bytes` IS NULL)
          OR (`action` = 'add_data' AND `target_expires_at` IS NULL AND `target_data_limit_bytes` IS NOT NULL)
          OR (`action` = 'add_data_days' AND `target_expires_at` IS NOT NULL AND `target_data_limit_bytes` IS NOT NULL)))
)
SQL);
        $this->replaceProvisioningOperationChecks(true);

        DB::unprepared($this->sqlFile('sql/service-paid-mutation-authority/service-update-guard.sql', 'Paid mutation Service update guard SQL is unavailable.'));
        DB::unprepared($this->sqlFile('sql/service-paid-mutation-authority/operation-update-guard.sql', 'Paid mutation operation update guard SQL is unavailable.'));
        DB::unprepared($this->sqlFile('sql/service-paid-mutation-authority/remote-effect-event-insert-guard.sql', 'Paid mutation remote-effect event guard SQL is unavailable.'));
        DB::unprepared($this->sqlFile('sql/service-paid-mutation-authority/delivery-effect-operation-insert-guard.sql', 'Paid mutation delivery fence SQL is unavailable.'));
        DB::unprepared($this->sqlFile('sql/service-paid-mutation-authority/history-insert-guard.sql', 'Paid mutation history guard SQL is unavailable.'));
        $this->createAuthorityGuards();
        $this->createRefundEffectFence(true);

        // Final enabling DDL. Everything that can consume a paid mutation is composed first.
        DB::unprepared($this->sqlFile('sql/service-paid-mutation-authority/operation-insert-guard.sql', 'Paid mutation operation guard SQL is unavailable.'));
        $this->dropPaidUpgradeFence();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_paid_mutation_authorities')
            && DB::table('service_paid_mutation_authorities')->exists()) {
            throw new RuntimeException('Cannot roll back paid Service mutation authority while paid Service mutations exist.');
        }
        if (Schema::hasTable('provisioning_operations')
            && DB::table('provisioning_operations')->whereIn('operation_type', self::PAID_OPERATION_TYPES)->exists()) {
            throw new RuntimeException('Cannot roll back paid Service mutation authority while paid mutation Operation evidence exists.');
        }
        $this->assertServicePackageQuoteAuthorityCanRollBack();

        $this->installPaidUpgradeFence();
        $this->createRefundEffectFence(false);
        DB::unprepared($this->sqlFile('sql/service-mutation-authority/remote-effect-event-insert-guard.sql', 'Prior Service mutation remote-effect event guard SQL is unavailable.'));
        DB::unprepared($this->sqlFile('migrations/support/service_delivery_effect_authority/05_mutation_insert_fence.sql', 'Prior Service mutation delivery fence SQL is unavailable.'));
        $this->restoreNonPaidOperationAuthority();
        DB::unprepared('DROP TRIGGER IF EXISTS service_paid_mutation_authorities_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_paid_mutation_authorities_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_paid_mutation_authorities_insert_guard');
        Schema::dropIfExists('service_paid_mutation_authorities');
        $this->replaceProvisioningOperationChecks(false);
        $this->dropPaidUpgradeFence();
    }

    private function sqlFile(string $relativePath, string $unavailableMessage): string
    {
        $sql = file_get_contents(database_path($relativePath));
        if (! is_string($sql) || $sql === '') {
            throw new RuntimeException($unavailableMessage);
        }

        return $sql;
    }

    private function assertServicePackageQuoteAuthorityCanRollBack(): void
    {
        if (Schema::hasColumn('quotes', 'action_snapshot')
            && DB::table('quotes')->where('action_snapshot', '<>', 'purchase')->exists()) {
            throw new RuntimeException('Cannot roll back paid Service mutation authority while Service-operation Quotes exist.');
        }

        foreach (['agent_pricing_rule_versions', 'agent_pricing_resolutions', 'pricing_rule_versions', 'pricing_rule_resolutions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('action', 'add_data_days')->exists()) {
                throw new RuntimeException('Cannot roll back paid Service mutation authority while combined-action pricing evidence exists.');
            }
        }
        if (Schema::hasTable('payment_method_eligibility_decisions')
            && DB::table('payment_method_eligibility_decisions')->where('action_snapshot', 'add_data_days')->exists()) {
            throw new RuntimeException('Cannot roll back paid Service mutation authority while combined-action payment decisions exist.');
        }
    }

    private function restoreNonPaidOperationAuthority(): void
    {
        // #150 owns the final pre-paid Operation/history composition and detects the later #152
        // Service operational guard, so reuse its convergent authority restoration on rollback.
        $migration = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Non-paid Order authority predecessor cannot be restored safely.');
        }

        $migration->up();
    }

    private function ensureAuthorityTable(): void
    {
        if (! Schema::hasTable('service_paid_mutation_authorities')) {
            Schema::create('service_paid_mutation_authorities', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->foreignId('provisioning_operation_id')->unique()->constrained('provisioning_operations')->restrictOnDelete();
                $table->foreignId('service_subscription_id')->constrained('service_subscriptions')->restrictOnDelete();
                $table->foreignId('source_quote_id')->unique()->constrained('quotes')->restrictOnDelete();
                $table->foreignId('purchase_order_id')->unique()->constrained('orders')->restrictOnDelete();
                $table->foreignId('purchase_order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
                $table->foreignId('purchase_settlement_id')->unique()->constrained('purchase_settlements')->restrictOnDelete();
                $table->foreignId('payment_intent_id')->unique()->constrained('payment_intents')->restrictOnDelete();
                $table->string('action', 32);
                $table->string('package_code', 64);
                $table->unsignedInteger('duration_days')->nullable();
                $table->unsignedBigInteger('data_bytes')->nullable();
                $table->foreignId('quoted_service_target_id')->constrained('panel_service_targets')->restrictOnDelete();
                $table->unsignedBigInteger('quoted_remote_identity_generation');
                $table->unsignedBigInteger('quoted_lifecycle_version');
                $table->char('remote_snapshot_hash', 64)->nullable();
                $table->dateTime('target_expires_at', 6)->nullable();
                $table->unsignedBigInteger('target_data_limit_bytes')->nullable();
                $table->dateTime('targets_resolved_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->index(['service_subscription_id', 'created_at'], 'service_paid_mutation_service_created_idx');
            });
        }

        foreach ([
            'id', 'public_id', 'provisioning_operation_id', 'service_subscription_id', 'source_quote_id',
            'purchase_order_id', 'purchase_order_item_id', 'purchase_settlement_id', 'payment_intent_id',
            'action', 'package_code', 'duration_days', 'data_bytes', 'quoted_service_target_id',
            'quoted_remote_identity_generation', 'quoted_lifecycle_version', 'remote_snapshot_hash',
            'target_expires_at', 'target_data_limit_bytes', 'targets_resolved_at', 'created_at',
        ] as $column) {
            if (! Schema::hasColumn('service_paid_mutation_authorities', $column)) {
                throw new RuntimeException('Paid Service mutation authority table is partially applied and cannot be safely normalized.');
            }
        }
    }

    private function replaceCheckConstraint(string $constraint, string $definition): void
    {
        $this->replaceTableCheckConstraint('service_paid_mutation_authorities', $constraint, $definition);
    }

    private function replaceProvisioningOperationChecks(bool $includePaidMutations): void
    {
        $types = $includePaidMutations
            ? "`operation_type` IN ('initial_provision','reset_usage','suspend','activate','delete','rotate_subscription_link','renew','add_data','add_days','add_data_days')"
            : "`operation_type` IN ('initial_provision','reset_usage','suspend','activate','delete','rotate_subscription_link')";
        $exactTypes = $includePaidMutations
            ? "BINARY `operation_type` IN (BINARY 'initial_provision', BINARY 'reset_usage', BINARY 'suspend', BINARY 'activate', BINARY 'delete', BINARY 'rotate_subscription_link', BINARY 'renew', BINARY 'add_data', BINARY 'add_days', BINARY 'add_data_days')"
            : "BINARY `operation_type` IN (BINARY 'initial_provision', BINARY 'reset_usage', BINARY 'suspend', BINARY 'activate', BINARY 'delete', BINARY 'rotate_subscription_link')";

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

    private function installPaidUpgradeFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_paid_mutation_upgrade_fence
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    IF NEW.operation_type IN ('renew','add_data','add_days','add_data_days') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Service mutation creation is fenced while authority migration converges.';
    END IF;
END
SQL);
    }

    private function dropPaidUpgradeFence(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_paid_mutation_upgrade_fence');
    }

    private function createAuthorityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_paid_mutation_authorities_insert_guard
BEFORE INSERT ON service_paid_mutation_authorities
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_mutation_authority, '') <> 'service_paid_mutation_queue_v1'
       OR NEW.remote_snapshot_hash IS NOT NULL
       OR NEW.target_expires_at IS NOT NULL
       OR NEW.target_data_limit_bytes IS NOT NULL
       OR NEW.targets_resolved_at IS NOT NULL
       OR NOT EXISTS (
           SELECT 1
           FROM provisioning_operations operation_row
           INNER JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
           INNER JOIN orders order_row ON order_row.id = NEW.purchase_order_id
           INNER JOIN order_items item_row ON item_row.id = NEW.purchase_order_item_id AND item_row.order_id = order_row.id
           INNER JOIN purchase_settlements settlement_row ON settlement_row.id = NEW.purchase_settlement_id
           INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
           INNER JOIN quotes quote_row ON quote_row.id = NEW.source_quote_id
           WHERE operation_row.id = NEW.provisioning_operation_id
             AND operation_row.service_subscription_id = NEW.service_subscription_id
             AND operation_row.order_id = order_row.id
             AND operation_row.order_item_id = item_row.id
             AND operation_row.operation_type = NEW.action
             AND operation_row.state = 'queued'
             AND operation_row.state_version = 1
             AND operation_row.operation_generation = service_row.mutation_generation
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
             AND quote_row.action_snapshot = NEW.action
             AND quote_row.service_subscription_id = service_row.id
             AND quote_row.service_package_code_snapshot = NEW.package_code
             AND (quote_row.service_package_duration_days_snapshot <=> NEW.duration_days)
             AND (quote_row.service_package_data_bytes_snapshot <=> NEW.data_bytes)
             AND quote_row.service_target_id_snapshot = NEW.quoted_service_target_id
             AND quote_row.service_remote_identity_generation_snapshot = NEW.quoted_remote_identity_generation
             AND quote_row.service_lifecycle_version_snapshot = NEW.quoted_lifecycle_version
             AND NOT EXISTS (
                 SELECT 1 FROM provisioning_financial_invalidations invalidation
                 WHERE invalidation.purchase_settlement_id = settlement_row.id
                   AND invalidation.payment_intent_id = intent_row.id
             )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Service mutation authority does not match captured purchase and current Service operation.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_paid_mutation_authorities_update_guard
BEFORE UPDATE ON service_paid_mutation_authorities
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_paid_mutation_target_authority, '') <> 'service_paid_mutation_target_v1'
       OR OLD.provisioning_operation_id <> COALESCE(@app_service_paid_mutation_operation_id, 0)
       OR BINARY NEW.remote_snapshot_hash <> BINARY COALESCE(@app_service_paid_mutation_snapshot_hash, '')
       OR NOT (NEW.target_expires_at <=> @app_service_paid_mutation_target_expires_at)
       OR NOT (NEW.target_data_limit_bytes <=> @app_service_paid_mutation_target_data_limit)
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR NEW.provisioning_operation_id <> OLD.provisioning_operation_id
       OR NEW.service_subscription_id <> OLD.service_subscription_id
       OR NEW.source_quote_id <> OLD.source_quote_id
       OR NEW.purchase_order_id <> OLD.purchase_order_id
       OR NEW.purchase_order_item_id <> OLD.purchase_order_item_id
       OR NEW.purchase_settlement_id <> OLD.purchase_settlement_id
       OR NEW.payment_intent_id <> OLD.payment_intent_id
       OR BINARY NEW.action <> BINARY OLD.action
       OR BINARY NEW.package_code <> BINARY OLD.package_code
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
             AND service_row.lifecycle_state IN ('active','suspended')
             AND NOT EXISTS (
                 SELECT 1 FROM provisioning_financial_invalidations invalidation
                 WHERE invalidation.purchase_settlement_id = OLD.purchase_settlement_id
                   AND invalidation.payment_intent_id = OLD.payment_intent_id
             )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Service mutation target resolution authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_paid_mutation_authorities_delete_guard
BEFORE DELETE ON service_paid_mutation_authorities
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Service mutation authority is non-deletable.';
END
SQL);
    }

    private function createRefundEffectFence(bool $includePaidMutations): void
    {
        if ($includePaidMutations) {
            DB::unprepared($this->sqlFile('sql/service-paid-mutation-authority/refund-invalidation-guard.sql', 'Paid mutation refund guard SQL is unavailable.'));

            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_refunds_provisioning_invalidation
AFTER INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE running_operation_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE existing_invalidation_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE existing_purchase_refund_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_existing_refund_count INT DEFAULT 0;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_order_id IS NOT NULL THEN
        SELECT id INTO running_operation_id
        FROM provisioning_operations
        WHERE order_id = locked_order_id
          AND operation_type = 'initial_provision'
          AND state = 'running'
        LIMIT 1
        FOR UPDATE;

        IF running_operation_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning remote-effect fence is active; retry refund after reconciliation.';
        END IF;
    END IF;

    SELECT id, purchase_refund_id
    INTO existing_invalidation_id, existing_purchase_refund_id
    FROM provisioning_financial_invalidations
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF existing_invalidation_id IS NOT NULL THEN
        SELECT COUNT(*) INTO valid_existing_refund_count
        FROM purchase_refunds
        WHERE id = existing_purchase_refund_id
          AND purchase_settlement_id = NEW.purchase_settlement_id
          AND payment_intent_id = NEW.payment_intent_id;

        IF valid_existing_refund_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing provisioning financial invalidation binding is inconsistent.';
        END IF;
    ELSE
        INSERT INTO provisioning_financial_invalidations (
            order_id, purchase_settlement_id, payment_intent_id, purchase_refund_id, created_at
        ) VALUES (
            locked_order_id, NEW.purchase_settlement_id, NEW.payment_intent_id, NEW.id, CURRENT_TIMESTAMP(6)
        );
    END IF;
END
SQL);
    }
};
