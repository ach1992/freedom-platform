<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 ARCH-003 ARCH-004 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
    public function up(): void
    {
        // MariaDB DDL commits per statement. Start every attempt from fail-closed Order guards,
        // then make each schema/trigger step restart-safe before enabling queue creation last.
        $this->restoreOriginalOrderHistoryGuard();
        $this->restoreOriginalOrderUpdateGuard();

        if (! Schema::hasTable('service_subscriptions')) {
            Schema::create('service_subscriptions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
                $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->string('creation_correlation_id', 64);
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->index(['order_id', 'created_at'], 'service_subscriptions_order_created_idx');
                $table->index(['user_id', 'created_at'], 'service_subscriptions_user_created_idx');
            });
        }
        $this->createFailClosedServiceGuards();

        if (! Schema::hasTable('provisioning_operations')) {
            Schema::create('provisioning_operations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->string('operation_key', 191)->unique();
                $table->string('operation_type', 32);
                $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
                $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
                $table->foreignId('service_subscription_id')->constrained('service_subscriptions')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->string('state', 32);
                $table->unsignedBigInteger('state_version');
                $table->string('correlation_id', 64);
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->unique(['order_item_id', 'operation_type'], 'provisioning_operations_item_type_unique');
                $table->index(['order_id', 'created_at'], 'provisioning_operations_order_created_idx');
                $table->index(['state', 'created_at'], 'provisioning_operations_state_created_idx');
            });
        }
        $this->createFailClosedProvisioningOperationGuards();

        if (! Schema::hasTable('provisioning_operation_histories')) {
            Schema::create('provisioning_operation_histories', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('provisioning_operation_id')->constrained('provisioning_operations', indexName: 'prov_op_hist_operation_fk')->restrictOnDelete();
                $table->string('from_state', 32)->nullable();
                $table->string('to_state', 32);
                $table->unsignedBigInteger('from_version')->nullable();
                $table->unsignedBigInteger('to_version');
                $table->string('actor_type', 16);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('reason_code', 64);
                $table->string('correlation_id', 64);
                $table->dateTime('created_at', 6);
                $table->unique(['provisioning_operation_id', 'to_version'], 'provisioning_operation_history_version_unique');
                $table->index(['provisioning_operation_id', 'created_at'], 'provisioning_operation_history_created_idx');
            });
        }
        $this->createFailClosedProvisioningHistoryGuards();

        $this->ensureConstraint('provisioning_operations', 'provisioning_operations_type_chk', "CHECK (`operation_type` = 'initial_provision')");
        $this->ensureConstraint('provisioning_operations', 'provisioning_operations_state_chk', "CHECK (`state` IN ('queued','running','uncertain_remote_result','retry_scheduled','succeeded','failed_final','needs_review','compensating','compensated'))");
        $this->ensureConstraint('provisioning_operations', 'provisioning_operations_state_version_chk', 'CHECK (`state_version` >= 1)');
        $this->ensureConstraint('provisioning_operation_histories', 'provisioning_operation_history_actor_chk', "CHECK (`actor_type` IN ('system','customer','agent','administrator'))");
        $this->ensureConstraint('provisioning_operation_histories', 'provisioning_operation_history_version_chk', 'CHECK (`to_version` >= 1 AND (`from_version` IS NULL OR `from_version` >= 1))');

        $this->replaceOrderPurchaseShapeConstraint(true);
        $this->createProvisioningInitialHistoryTrigger();
        $this->replaceOrderHistoryGuard();
        $this->createProvisioningHistoryGuard();
        $this->createProvisioningOperationInsertGuard();
        $this->replaceOrderUpdateGuard();

        // Queue creation becomes possible only after every downstream guard is installed.
        $this->createServiceInsertGuard();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_subscriptions') && DB::table('service_subscriptions')->exists()) {
            throw new RuntimeException('Cannot roll back provisioning queue authority while Service Subscriptions exist.');
        }
        if (DB::table('orders')->where('state', 'provisioning_queued')->exists()) {
            throw new RuntimeException('Cannot roll back provisioning queue authority while queued Orders exist.');
        }

        if (Schema::hasTable('service_subscriptions')) {
            $this->createFailClosedServiceGuards();
        }
        if (Schema::hasTable('provisioning_operations')) {
            $this->createFailClosedProvisioningOperationGuards();
        }
        if (Schema::hasTable('provisioning_operation_histories')) {
            $this->createFailClosedProvisioningHistoryGuards();
        }

        $this->restoreOriginalOrderUpdateGuard();
        $this->restoreOriginalOrderHistoryGuard();
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_history');
        $this->replaceOrderPurchaseShapeConstraint(false);

        Schema::dropIfExists('provisioning_operation_histories');
        Schema::dropIfExists('provisioning_operations');
        Schema::dropIfExists('service_subscriptions');
    }

    private function ensureConstraint(string $table, string $constraint, string $definition): void
    {
        if (! $this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
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

    private function replaceOrderPurchaseShapeConstraint(bool $allowProvisioningQueued): void
    {
        $definition = $allowProvisioningQueued
            ? "CHECK (`source_type` <> 'purchase' OR (`purchase_settlement_id` IS NOT NULL AND `purchase_settlement_public_id` IS NOT NULL AND `payment_intent_id` IS NOT NULL AND `payment_intent_public_id` IS NOT NULL AND `source_quote_id` IS NOT NULL AND `source_quote_public_id` IS NOT NULL AND `source_quote_configuration_hash` IS NOT NULL AND `paid_at` IS NOT NULL AND `total_amount_irr` > 0 AND `settled_amount_irr` > 0))"
            : "CHECK (`source_type` <> 'purchase' OR (`purchase_settlement_id` IS NOT NULL AND `purchase_settlement_public_id` IS NOT NULL AND `payment_intent_id` IS NOT NULL AND `payment_intent_public_id` IS NOT NULL AND `source_quote_id` IS NOT NULL AND `source_quote_public_id` IS NOT NULL AND `source_quote_configuration_hash` IS NOT NULL AND `state` = 'paid' AND `state_version` = 1 AND `paid_at` IS NOT NULL AND `total_amount_irr` > 0 AND `settled_amount_irr` > 0))";

        if ($this->constraintExists('orders', 'orders_purchase_shape_chk')) {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT orders_purchase_shape_chk, ADD CONSTRAINT orders_purchase_shape_chk {$definition}");

            return;
        }

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_purchase_shape_chk {$definition}");
    }

    private function createFailClosedServiceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_insert_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription creation is disabled until provisioning authority migration completes.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_update_guard
BEFORE UPDATE ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription identity is immutable in current provisioning authority.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_delete_guard
BEFORE DELETE ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscriptions are non-deletable.';
END
SQL);
    }

    private function createFailClosedProvisioningOperationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_insert_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation creation is disabled until provisioning authority migration completes.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_update_guard
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation mutation is not enabled by current queue authority.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_delete_guard
BEFORE DELETE ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operations are non-deletable.';
END
SQL);
    }

    private function createFailClosedProvisioningHistoryGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operation_histories_insert_guard
BEFORE INSERT ON provisioning_operation_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation history creation is disabled until provisioning authority migration completes.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operation_histories_update_guard
BEFORE UPDATE ON provisioning_operation_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation history is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operation_histories_delete_guard
BEFORE DELETE ON provisioning_operation_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation history is non-deletable.';
END
SQL);
    }

    private function createServiceInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_insert_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_authority_order_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT purchase_settlement_id, payment_intent_id INTO authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires one currently captured authoritative purchase Order Item.';
    END IF;

    SELECT id INTO locked_settlement_id FROM purchase_settlements WHERE id = authority_settlement_id LIMIT 1 FOR UPDATE;
    SELECT id INTO locked_intent_id FROM payment_intents WHERE id = authority_intent_id LIMIT 1 FOR UPDATE;

    SELECT order_row.id INTO valid_authority_order_id
    FROM orders order_row
    INNER JOIN order_items item_row ON item_row.id = NEW.order_item_id
    INNER JOIN purchase_settlements settlement_row ON settlement_row.id = order_row.purchase_settlement_id
    INNER JOIN payment_intents intent_row ON intent_row.id = order_row.payment_intent_id
    WHERE order_row.id = NEW.order_id
      AND settlement_row.id = locked_settlement_id
      AND intent_row.id = locked_intent_id
      AND item_row.order_id = order_row.id
      AND item_row.line_number = 1
      AND order_row.user_id = NEW.user_id
      AND order_row.source_type = 'purchase'
      AND order_row.state = 'paid'
      AND order_row.state_version = 1
      AND settlement_row.payment_intent_id = intent_row.id
      AND settlement_row.user_id = order_row.user_id
      AND settlement_row.source_quote_id = order_row.source_quote_id
      AND settlement_row.public_id = order_row.purchase_settlement_public_id
      AND intent_row.public_id = order_row.payment_intent_public_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.wallet_account_id IS NULL
      AND intent_row.user_id = order_row.user_id
      AND intent_row.source_quote_id = order_row.source_quote_id
      AND intent_row.source_quote_public_id = order_row.source_quote_public_id
      AND intent_row.source_quote_configuration_hash = order_row.source_quote_configuration_hash
      AND intent_row.amount_irr = order_row.total_amount_irr
      AND intent_row.currency = order_row.currency
      AND intent_row.state = 'captured'
      AND intent_row.captured_at IS NOT NULL
    LIMIT 1
    FOR UPDATE;

    IF valid_authority_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires one currently captured authoritative purchase Order Item.';
    END IF;
END
SQL);
    }

    private function createProvisioningOperationInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_insert_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_authority_order_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT purchase_settlement_id, payment_intent_id INTO authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires matching captured purchase authority and Service identity.';
    END IF;

    SELECT id INTO locked_settlement_id FROM purchase_settlements WHERE id = authority_settlement_id LIMIT 1 FOR UPDATE;
    SELECT id INTO locked_intent_id FROM payment_intents WHERE id = authority_intent_id LIMIT 1 FOR UPDATE;

    SELECT order_row.id INTO valid_authority_order_id
    FROM orders order_row
    INNER JOIN order_items item_row ON item_row.id = NEW.order_item_id
    INNER JOIN service_subscriptions service_row ON service_row.id = NEW.service_subscription_id
    INNER JOIN purchase_settlements settlement_row ON settlement_row.id = order_row.purchase_settlement_id
    INNER JOIN payment_intents intent_row ON intent_row.id = order_row.payment_intent_id
    WHERE order_row.id = NEW.order_id
      AND settlement_row.id = locked_settlement_id
      AND intent_row.id = locked_intent_id
      AND item_row.order_id = order_row.id
      AND service_row.order_id = order_row.id
      AND service_row.order_item_id = item_row.id
      AND service_row.user_id = order_row.user_id
      AND NEW.user_id = order_row.user_id
      AND NEW.operation_type = 'initial_provision'
      AND NEW.operation_key = CONCAT('initial-provision:', item_row.public_id)
      AND NEW.state = 'queued'
      AND NEW.state_version = 1
      AND order_row.source_type = 'purchase'
      AND order_row.state = 'paid'
      AND order_row.state_version = 1
      AND settlement_row.payment_intent_id = intent_row.id
      AND intent_row.purpose = 'purchase'
      AND intent_row.wallet_account_id IS NULL
      AND intent_row.state = 'captured'
      AND intent_row.captured_at IS NOT NULL
    LIMIT 1
    FOR UPDATE;

    IF valid_authority_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires matching captured purchase authority and Service identity.';
    END IF;
END
SQL);
    }

    private function createProvisioningHistoryGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operation_histories_insert_guard
BEFORE INSERT ON provisioning_operation_histories
FOR EACH ROW
BEGIN
    DECLARE valid_history_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT operation_row.id INTO valid_history_id
    FROM provisioning_operations operation_row
    WHERE operation_row.id = NEW.provisioning_operation_id
      AND operation_row.operation_type = 'initial_provision'
      AND operation_row.state = 'queued'
      AND operation_row.state_version = 1
      AND operation_row.correlation_id = NEW.correlation_id
      AND NEW.from_state IS NULL
      AND NEW.from_version IS NULL
      AND NEW.to_state = 'queued'
      AND NEW.to_version = 1
      AND NEW.actor_type = 'system'
      AND NEW.actor_id IS NULL
      AND NEW.reason_code = 'initial_provisioning_requested'
    LIMIT 1;

    IF valid_history_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning Operation history insertion is not authorized.';
    END IF;
END
SQL);
    }

    private function createProvisioningInitialHistoryTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operation_initial_history
AFTER INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    INSERT INTO provisioning_operation_histories (
        provisioning_operation_id, from_state, to_state, from_version, to_version,
        actor_type, actor_id, reason_code, correlation_id, created_at
    ) VALUES (
        NEW.id, NULL, NEW.state, NULL, NEW.state_version,
        'system', NULL, 'initial_provisioning_requested', NEW.correlation_id, NEW.created_at
    );
END
SQL);
    }

    private function replaceOrderUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_update_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_queue_operation_id BIGINT UNSIGNED DEFAULT NULL;

    IF NOT (OLD.public_id <=> NEW.public_id)
       OR NOT (OLD.source_type <=> NEW.source_type)
       OR NOT (OLD.purchase_settlement_id <=> NEW.purchase_settlement_id)
       OR NOT (OLD.purchase_settlement_public_id <=> NEW.purchase_settlement_public_id)
       OR NOT (OLD.payment_intent_id <=> NEW.payment_intent_id)
       OR NOT (OLD.payment_intent_public_id <=> NEW.payment_intent_public_id)
       OR NOT (OLD.user_id <=> NEW.user_id)
       OR NOT (OLD.source_quote_id <=> NEW.source_quote_id)
       OR NOT (OLD.source_quote_public_id <=> NEW.source_quote_public_id)
       OR NOT (OLD.source_quote_configuration_hash <=> NEW.source_quote_configuration_hash)
       OR NOT (OLD.total_amount_irr <=> NEW.total_amount_irr)
       OR NOT (OLD.settled_amount_irr <=> NEW.settled_amount_irr)
       OR NOT (OLD.currency <=> NEW.currency)
       OR NOT (OLD.paid_at <=> NEW.paid_at)
       OR NOT (OLD.creation_correlation_id <=> NEW.creation_correlation_id)
       OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order commercial and authority identity is immutable.';
    END IF;

    IF OLD.state <> 'paid'
       OR OLD.state_version <> 1
       OR NEW.state <> 'provisioning_queued'
       OR NEW.state_version <> 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only paid/v1 to provisioning_queued/v2 is enabled by current Order lifecycle authority.';
    END IF;

    SELECT operation_row.id INTO valid_queue_operation_id
    FROM purchase_settlements settlement_row
    INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
    INNER JOIN order_items item_row ON item_row.order_id = OLD.id AND item_row.line_number = 1
    INNER JOIN service_subscriptions service_row
        ON service_row.order_id = OLD.id AND service_row.order_item_id = item_row.id AND service_row.user_id = OLD.user_id
    INNER JOIN provisioning_operations operation_row
        ON operation_row.order_id = OLD.id
       AND operation_row.order_item_id = item_row.id
       AND operation_row.service_subscription_id = service_row.id
       AND operation_row.user_id = OLD.user_id
       AND operation_row.operation_type = 'initial_provision'
       AND operation_row.operation_key = CONCAT('initial-provision:', item_row.public_id)
       AND operation_row.state = 'queued'
       AND operation_row.state_version = 1
    INNER JOIN outbox_messages outbox_row
        ON outbox_row.event_key = CONCAT('provisioning.initial.requested:', operation_row.public_id)
       AND outbox_row.event_type = 'provisioning.initial.requested'
       AND outbox_row.aggregate_type = 'provisioning_operation'
       AND outbox_row.aggregate_id = operation_row.public_id
    WHERE settlement_row.id = OLD.purchase_settlement_id
      AND settlement_row.public_id = OLD.purchase_settlement_public_id
      AND settlement_row.payment_intent_id = OLD.payment_intent_id
      AND settlement_row.user_id = OLD.user_id
      AND settlement_row.source_quote_id = OLD.source_quote_id
      AND intent_row.id = OLD.payment_intent_id
      AND intent_row.public_id = OLD.payment_intent_public_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.wallet_account_id IS NULL
      AND intent_row.user_id = OLD.user_id
      AND intent_row.source_quote_id = OLD.source_quote_id
      AND intent_row.source_quote_public_id = OLD.source_quote_public_id
      AND intent_row.source_quote_configuration_hash = OLD.source_quote_configuration_hash
      AND intent_row.amount_irr = OLD.total_amount_irr
      AND intent_row.currency = OLD.currency
      AND intent_row.state = 'captured'
      AND intent_row.captured_at IS NOT NULL
      AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.order_public_id')) = OLD.public_id
      AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.order_item_public_id')) = item_row.public_id
      AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.service_subscription_public_id')) = service_row.public_id
      AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.provisioning_operation_public_id')) = operation_row.public_id
      AND LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)) = LOWER(outbox_row.payload_hash)
    LIMIT 1;

    IF valid_queue_operation_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order provisioning queue transition requires captured payment, durable local identities, and one matching Outbox command.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_provisioning_history
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE operation_correlation VARCHAR(64);

    IF OLD.state = 'paid' AND OLD.state_version = 1
       AND NEW.state = 'provisioning_queued' AND NEW.state_version = 2 THEN
        SELECT correlation_id INTO operation_correlation
        FROM provisioning_operations
        WHERE order_id = NEW.id AND operation_type = 'initial_provision'
        LIMIT 1;

        INSERT INTO order_state_histories (
            order_id, from_state, to_state, from_version, to_version, actor_type, actor_id,
            reason_code, correlation_id, created_at
        ) VALUES (
            NEW.id, OLD.state, NEW.state, OLD.state_version, NEW.state_version, 'system', NULL,
            'initial_provisioning_queued', operation_correlation, NEW.updated_at
        );
    END IF;
END
SQL);
    }

    private function replaceOrderHistoryGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_state_histories_insert_guard
BEFORE INSERT ON order_state_histories
FOR EACH ROW
BEGIN
    DECLARE valid_transition_count INT DEFAULT 0;

    IF NEW.from_state IS NULL
       AND NEW.from_version IS NULL
       AND NEW.to_state = 'paid'
       AND NEW.to_version = 1
       AND NEW.actor_type = 'system'
       AND NEW.actor_id IS NULL
       AND NEW.reason_code = 'authoritative_purchase_settlement' THEN
        SET valid_transition_count = 1;
    ELSEIF NEW.from_state = 'paid'
       AND NEW.from_version = 1
       AND NEW.to_state = 'provisioning_queued'
       AND NEW.to_version = 2
       AND NEW.actor_type = 'system'
       AND NEW.actor_id IS NULL
       AND NEW.reason_code = 'initial_provisioning_queued' THEN
        SELECT COUNT(*) INTO valid_transition_count
        FROM orders order_row
        INNER JOIN provisioning_operations operation_row
            ON operation_row.order_id = order_row.id AND operation_row.operation_type = 'initial_provision'
        WHERE order_row.id = NEW.order_id
          AND order_row.state = 'provisioning_queued'
          AND order_row.state_version = 2
          AND operation_row.state = 'queued'
          AND operation_row.state_version = 1
          AND operation_row.correlation_id = NEW.correlation_id;
    END IF;

    IF valid_transition_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order state history insertion is not authorized.';
    END IF;
END
SQL);
    }

    private function restoreOriginalOrderHistoryGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_state_histories_insert_guard
BEFORE INSERT ON order_state_histories
FOR EACH ROW
BEGIN
    IF NEW.from_state IS NOT NULL
       OR NEW.from_version IS NOT NULL
       OR NEW.to_state <> 'paid'
       OR NEW.to_version <> 1
       OR NEW.actor_type <> 'system'
       OR NEW.actor_id IS NOT NULL
       OR NEW.reason_code <> 'authoritative_purchase_settlement' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order state history insertion is not authorized.';
    END IF;
END
SQL);
    }

    private function restoreOriginalOrderUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_update_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order mutation is not enabled by the current lifecycle authority.';
END
SQL);
    }
};
