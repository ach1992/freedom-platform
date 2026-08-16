<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 PAY-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function up(): void
    {
        $duplicateQuote = DB::table('purchase_settlements')
            ->select('source_quote_id')
            ->whereNotNull('source_quote_id')
            ->groupBy('source_quote_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicateQuote !== null) {
            throw new RuntimeException('Cannot activate pre-payment Order authority while one Quote has multiple purchase settlements.');
        }

        if (! $this->constraintExists('purchase_settlements', 'purchase_settlements_quote_unique')) {
            DB::statement('ALTER TABLE purchase_settlements ADD CONSTRAINT purchase_settlements_quote_unique UNIQUE (`source_quote_id`)');
        }

        $this->replaceConstraint('orders', 'orders_state_version_chk', 'CHECK (`state_version` >= 0)');
        $this->replaceConstraint(
            'order_state_histories',
            'order_state_history_version_chk',
            'CHECK (`to_version` >= 0 AND (`from_version` IS NULL OR `from_version` >= 0))',
        );

        if ($this->constraintExists('orders', 'orders_purchase_settled_amount_chk')) {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_purchase_settled_amount_chk');
        }

        $this->replaceConstraint(
            'orders',
            'orders_purchase_shape_chk',
            <<<'SQL'
CHECK (
    `source_type` <> 'purchase'
    OR (
        `source_quote_id` IS NOT NULL
        AND `source_quote_public_id` IS NOT NULL
        AND `source_quote_configuration_hash` IS NOT NULL
        AND `total_amount_irr` > 0
        AND (
            (
                `state` = 'awaiting_payment'
                AND `state_version` = 0
                AND `purchase_settlement_id` IS NULL
                AND `purchase_settlement_public_id` IS NULL
                AND `payment_intent_id` IS NULL
                AND `payment_intent_public_id` IS NULL
                AND `settled_amount_irr` IS NULL
                AND `paid_at` IS NULL
            )
            OR (
                `state` <> 'awaiting_payment'
                AND `purchase_settlement_id` IS NOT NULL
                AND `purchase_settlement_public_id` IS NOT NULL
                AND `payment_intent_id` IS NOT NULL
                AND `payment_intent_public_id` IS NOT NULL
                AND `settled_amount_irr` > 0
                AND `paid_at` IS NOT NULL
            )
        )
    )
)
SQL,
        );

        $this->replaceOrderInsertGuard();
        $this->replaceInitialHistoryTrigger();
        $this->replaceOrderHistoryGuard();
        $this->replaceOrderUpdateGuard();
    }

    public function down(): void
    {
        if (DB::table('orders')->where('source_type', 'purchase')->where('state_version', 0)->exists()
            || DB::table('order_state_histories')->where('to_version', 0)->exists()) {
            throw new RuntimeException('Cannot roll back pre-payment Order authority while version-zero purchase Orders or history exist.');
        }

        $this->replaceConstraint('orders', 'orders_state_version_chk', 'CHECK (`state_version` >= 1)');
        $this->replaceConstraint(
            'order_state_histories',
            'order_state_history_version_chk',
            'CHECK (`to_version` >= 1 AND (`from_version` IS NULL OR `from_version` >= 1))',
        );
        $this->replaceConstraint(
            'orders',
            'orders_purchase_shape_chk',
            "CHECK (`source_type` <> 'purchase' OR (`purchase_settlement_id` IS NOT NULL AND `purchase_settlement_public_id` IS NOT NULL AND `payment_intent_id` IS NOT NULL AND `payment_intent_public_id` IS NOT NULL AND `source_quote_id` IS NOT NULL AND `source_quote_public_id` IS NOT NULL AND `source_quote_configuration_hash` IS NOT NULL AND `paid_at` IS NOT NULL AND `total_amount_irr` > 0 AND `settled_amount_irr` > 0))",
        );
        if (! $this->constraintExists('orders', 'orders_purchase_settled_amount_chk')) {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_purchase_settled_amount_chk CHECK (`source_type` <> 'purchase' OR `settled_amount_irr` IS NOT NULL)");
        }
        if ($this->constraintExists('purchase_settlements', 'purchase_settlements_quote_unique')) {
            DB::statement('ALTER TABLE purchase_settlements DROP CONSTRAINT purchase_settlements_quote_unique');
        }

        $this->restorePaidOnlyInsertGuard();
        $this->restorePaidOnlyInitialHistoryTrigger();

        $activation = require __DIR__.'/2026_08_14_001165_activate_provisioning_queue_authority.php';
        if (! is_object($activation) || ! method_exists($activation, 'up')) {
            throw new RuntimeException('Provisioning queue authority cannot be restored during pre-payment Order rollback.');
        }
        $activation->up();
    }

    private function replaceOrderInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_purchase_count INT DEFAULT 0;

    IF NEW.source_type = 'purchase'
       AND NEW.state = 'awaiting_payment'
       AND NEW.state_version = 0 THEN
        IF NEW.purchase_settlement_id IS NOT NULL
           OR NEW.purchase_settlement_public_id IS NOT NULL
           OR NEW.payment_intent_id IS NOT NULL
           OR NEW.payment_intent_public_id IS NOT NULL
           OR NEW.settled_amount_irr IS NOT NULL
           OR NEW.paid_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order cannot claim captured financial authority.';
        END IF;

        SELECT COUNT(*) INTO valid_purchase_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id
          AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id
          AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.final_price_irr = NEW.total_amount_irr
          AND quote_row.currency = NEW.currency;

        IF valid_purchase_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order must match one immutable purchase Quote.';
        END IF;
    ELSEIF NEW.source_type = 'purchase' THEN
        SELECT COUNT(*) INTO valid_purchase_count
        FROM purchase_settlements settlement_row
        INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
        INNER JOIN quotes quote_row ON quote_row.id = settlement_row.source_quote_id
        WHERE settlement_row.id = NEW.purchase_settlement_id
          AND settlement_row.public_id = NEW.purchase_settlement_public_id
          AND settlement_row.payment_intent_id = NEW.payment_intent_id
          AND settlement_row.user_id = NEW.user_id
          AND settlement_row.source_quote_id = NEW.source_quote_id
          AND settlement_row.source_quote_public_id = NEW.source_quote_public_id
          AND settlement_row.amount_irr = NEW.settled_amount_irr
          AND settlement_row.currency = NEW.currency
          AND settlement_row.settled_at = NEW.paid_at
          AND intent_row.public_id = NEW.payment_intent_public_id
          AND intent_row.purpose = 'purchase'
          AND intent_row.user_id = NEW.user_id
          AND intent_row.source_quote_id = NEW.source_quote_id
          AND intent_row.source_quote_public_id = NEW.source_quote_public_id
          AND intent_row.source_quote_configuration_hash = NEW.source_quote_configuration_hash
          AND intent_row.amount_irr = NEW.total_amount_irr
          AND intent_row.currency = NEW.currency
          AND intent_row.state IN ('captured','refund_pending','refunded','partially_refunded')
          AND intent_row.captured_at IS NOT NULL
          AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id
          AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.final_price_irr = NEW.total_amount_irr
          AND quote_row.currency = NEW.currency;

        IF valid_purchase_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Order requires one matching authoritative purchase settlement.';
        END IF;
    END IF;
END
SQL);
    }

    private function replaceInitialHistoryTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_initial_history
AFTER INSERT ON orders
FOR EACH ROW
BEGIN
    IF NEW.source_type = 'purchase' AND NEW.state = 'awaiting_payment' AND NEW.state_version = 0 THEN
        INSERT INTO order_state_histories (
            order_id, from_state, to_state, from_version, to_version, actor_type, actor_id,
            reason_code, correlation_id, created_at
        ) VALUES (
            NEW.id, NULL, NEW.state, NULL, NEW.state_version, 'system', NULL,
            'purchase_quote_accepted', NEW.creation_correlation_id, NEW.created_at
        );
    ELSEIF NEW.source_type = 'purchase' AND NEW.state = 'paid' AND NEW.state_version = 1 THEN
        INSERT INTO order_state_histories (
            order_id, from_state, to_state, from_version, to_version, actor_type, actor_id,
            reason_code, correlation_id, created_at
        ) VALUES (
            NEW.id, NULL, NEW.state, NULL, NEW.state_version, 'system', NULL,
            'authoritative_purchase_settlement', NEW.creation_correlation_id, NEW.created_at
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
       AND NEW.to_state = 'awaiting_payment'
       AND NEW.to_version = 0
       AND NEW.actor_type = 'system'
       AND NEW.actor_id IS NULL
       AND NEW.reason_code = 'purchase_quote_accepted' THEN
        SELECT COUNT(*) INTO valid_transition_count
        FROM orders
        WHERE id = NEW.order_id
          AND source_type = 'purchase'
          AND state = 'awaiting_payment'
          AND state_version = 0
          AND purchase_settlement_id IS NULL
          AND payment_intent_id IS NULL;
    ELSEIF NEW.from_state IS NULL
       AND NEW.from_version IS NULL
       AND NEW.to_state = 'paid'
       AND NEW.to_version = 1
       AND NEW.actor_type = 'system'
       AND NEW.actor_id IS NULL
       AND NEW.reason_code = 'authoritative_purchase_settlement' THEN
        SET valid_transition_count = 1;
    ELSEIF NEW.from_state = 'awaiting_payment'
       AND NEW.from_version = 0
       AND NEW.to_state = 'paid'
       AND NEW.to_version = 1
       AND NEW.actor_type = 'system'
       AND NEW.actor_id IS NULL
       AND NEW.reason_code = 'authoritative_purchase_settlement' THEN
        SELECT COUNT(*) INTO valid_transition_count
        FROM orders
        WHERE id = NEW.order_id
          AND source_type = 'purchase'
          AND state = 'paid'
          AND state_version = 1
          AND purchase_settlement_id IS NOT NULL
          AND payment_intent_id IS NOT NULL;
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

    private function replaceOrderUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_update_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;
    DECLARE valid_queue_operation_id BIGINT UNSIGNED DEFAULT NULL;

    IF OLD.source_type = 'purchase'
       AND OLD.state = 'awaiting_payment'
       AND OLD.state_version = 0
       AND NEW.state = 'paid'
       AND NEW.state_version = 1 THEN
        IF NOT (OLD.public_id <=> NEW.public_id)
           OR NOT (OLD.source_type <=> NEW.source_type)
           OR NOT (OLD.user_id <=> NEW.user_id)
           OR NOT (OLD.source_quote_id <=> NEW.source_quote_id)
           OR NOT (OLD.source_quote_public_id <=> NEW.source_quote_public_id)
           OR NOT (OLD.source_quote_configuration_hash <=> NEW.source_quote_configuration_hash)
           OR NOT (OLD.total_amount_irr <=> NEW.total_amount_irr)
           OR NOT (OLD.currency <=> NEW.currency)
           OR NOT (OLD.creation_correlation_id <=> NEW.creation_correlation_id)
           OR NOT (OLD.created_at <=> NEW.created_at)
           OR OLD.purchase_settlement_id IS NOT NULL
           OR OLD.purchase_settlement_public_id IS NOT NULL
           OR OLD.payment_intent_id IS NOT NULL
           OR OLD.payment_intent_public_id IS NOT NULL
           OR OLD.settled_amount_irr IS NOT NULL
           OR OLD.paid_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order immutable identity is inconsistent.';
        END IF;

        SELECT COUNT(*) INTO valid_authority_count
        FROM purchase_settlements settlement_row
        INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
        INNER JOIN quotes quote_row ON quote_row.id = settlement_row.source_quote_id
        WHERE settlement_row.id = NEW.purchase_settlement_id
          AND settlement_row.public_id = NEW.purchase_settlement_public_id
          AND settlement_row.payment_intent_id = NEW.payment_intent_id
          AND settlement_row.user_id = NEW.user_id
          AND settlement_row.source_quote_id = NEW.source_quote_id
          AND settlement_row.source_quote_public_id = NEW.source_quote_public_id
          AND settlement_row.amount_irr = NEW.settled_amount_irr
          AND settlement_row.currency = NEW.currency
          AND settlement_row.settled_at = NEW.paid_at
          AND intent_row.id = NEW.payment_intent_id
          AND intent_row.public_id = NEW.payment_intent_public_id
          AND intent_row.purpose = 'purchase'
          AND intent_row.user_id = NEW.user_id
          AND intent_row.source_quote_id = NEW.source_quote_id
          AND intent_row.source_quote_public_id = NEW.source_quote_public_id
          AND intent_row.source_quote_configuration_hash = NEW.source_quote_configuration_hash
          AND intent_row.amount_irr = NEW.total_amount_irr
          AND intent_row.currency = NEW.currency
          AND intent_row.state IN ('captured','refund_pending','refunded','partially_refunded')
          AND intent_row.captured_at IS NOT NULL
          AND quote_row.id = NEW.source_quote_id
          AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id
          AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.final_price_irr = NEW.total_amount_irr
          AND quote_row.currency = NEW.currency;

        IF valid_authority_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order can become paid only from one matching authoritative settlement.';
        END IF;
    ELSE
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
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only pre-payment/v0 to paid/v1 or paid/v1 to provisioning_queued/v2 is enabled by current Order lifecycle authority.';
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
    END IF;
END
SQL);
    }

    private function restorePaidOnlyInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_purchase_count INT DEFAULT 0;

    IF NEW.source_type <> 'purchase' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported Order source type.';
    END IF;

    SELECT COUNT(*) INTO valid_purchase_count
    FROM purchase_settlements settlement_row
    INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
    INNER JOIN quotes quote_row ON quote_row.id = settlement_row.source_quote_id
    WHERE settlement_row.id = NEW.purchase_settlement_id
      AND settlement_row.public_id = NEW.purchase_settlement_public_id
      AND settlement_row.payment_intent_id = NEW.payment_intent_id
      AND settlement_row.user_id = NEW.user_id
      AND settlement_row.source_quote_id = NEW.source_quote_id
      AND settlement_row.source_quote_public_id = NEW.source_quote_public_id
      AND settlement_row.amount_irr = NEW.settled_amount_irr
      AND settlement_row.currency = NEW.currency
      AND settlement_row.settled_at = NEW.paid_at
      AND intent_row.public_id = NEW.payment_intent_public_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.user_id = NEW.user_id
      AND intent_row.source_quote_id = NEW.source_quote_id
      AND intent_row.source_quote_public_id = NEW.source_quote_public_id
      AND intent_row.source_quote_configuration_hash = NEW.source_quote_configuration_hash
      AND intent_row.amount_irr = NEW.total_amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.state IN ('captured','refund_pending','refunded','partially_refunded')
      AND intent_row.captured_at IS NOT NULL
      AND quote_row.public_id = NEW.source_quote_public_id
      AND quote_row.user_id = NEW.user_id
      AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
      AND quote_row.final_price_irr = NEW.total_amount_irr
      AND quote_row.currency = NEW.currency;

    IF valid_purchase_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Order requires one matching authoritative purchase settlement.';
    END IF;
END
SQL);
    }

    private function restorePaidOnlyInitialHistoryTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_initial_history
AFTER INSERT ON orders
FOR EACH ROW
BEGIN
    INSERT INTO order_state_histories (
        order_id, from_state, to_state, from_version, to_version, actor_type, actor_id,
        reason_code, correlation_id, created_at
    ) VALUES (
        NEW.id, NULL, NEW.state, NULL, NEW.state_version, 'system', NULL,
        'authoritative_purchase_settlement', NEW.creation_correlation_id, NEW.created_at
    );
END
SQL);
    }

    private function replaceConstraint(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
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
