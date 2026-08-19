<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 CAT-006 ADM-002 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
    public function up(): void
    {
        // Historical provisioning-authority re-entry can replace trigger surfaces installed by
        // the already-recorded Service-mutation migration. Re-establish that accepted predecessor
        // authority before composing #150 so restart/re-entry cannot enable non-paid sources on
        // top of an older initial-provision-only trigger graph.
        $this->restoreServiceMutationPredecessorAuthorities();

        // MariaDB commits DDL statement-by-statement. Keep the predecessor non-purchase insert
        // fence closed until every compatible constraint and trigger below has been installed.
        $this->replaceOrderSourceConstraints();
        $this->replaceOrderInsertGuard();
        $this->replaceOrderItemInsertGuard();
        $this->replaceInitialHistoryTrigger();
        $this->replaceOrderHistoryGuard();
        $this->replaceServiceInsertGuard();
        $this->replaceProvisioningOperationInsertGuard();
        $this->replaceOrderUpdateGuard();
        $this->replaceProvisioningOutboxEnvelopeGuard();
        $this->replaceOutboxReleaseGuard();

        $this->assertAuthorityReady();

        // Final enabling statement. A crash before this point leaves every non-purchase Order
        // source fenced exactly as before this migration.
        $this->activateSupportedSourceFence();
    }

    public function down(): void
    {
        // Rollback closes new source creation at the first durable statement. Existing non-paid
        // Orders cannot be downgraded into purchase-only lifecycle authority safely.
        $this->restorePurchaseOnlySourceFence();

        if (DB::table('orders')->whereIn('source_type', ['trial', 'benefit_code', 'admin_grant'])->exists()) {
            throw new RuntimeException('Cannot roll back non-paid Order authority while source-authorized Orders exist.');
        }

        $this->restorePurchaseOrderItemInsertGuard();
        $this->replaceConstraint('orders', 'orders_source_type_chk', "CHECK (`source_type` = 'purchase')");
        $this->replaceConstraint(
            'orders',
            'orders_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (`source_type` <> 'purchase' OR BINARY `source_type` = BINARY 'purchase')
    AND (
        `state` NOT IN ('paid', 'provisioning_queued')
        OR BINARY `state` IN (BINARY 'paid', BINARY 'provisioning_queued')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );

        // Re-enter the accepted purchase-only authority chain instead of maintaining a second
        // rollback copy of those security-sensitive triggers.
        foreach ([
            '2026_08_14_001164_harden_initial_provisioning_outbox_order_authority.php',
            '2026_08_14_001165_activate_provisioning_queue_authority.php',
            '2026_08_17_000100_reconcile_pre_payment_order_authority.php',
            '2026_08_17_000101_split_pre_payment_order_shape_constraints.php',
            '2026_08_17_000102_harden_purchase_order_insert_lifecycle_authority.php',
        ] as $file) {
            $migration = require __DIR__.'/'.$file;
            if (! is_object($migration) || ! method_exists($migration, 'up')) {
                throw new RuntimeException('Purchase-only Order authority cannot be restored safely.');
            }
            $migration->up();
        }

        // 001165 re-enters 001162, which predates Service-mutation authority and replaces
        // Service/Operation insert + update guards and provisioning history authority. Restore the
        // complete later accepted predecessor surface without replaying 000300 and disturbing its
        // shape/constraint descendants or unrelated later delivery authority.
        $this->restoreServiceMutationPredecessorAuthorities();

        // The source fence remains purchase-only even if a predecessor re-entry changes another
        // Order trigger. This is the rollback fail-closed boundary.
        $this->restorePurchaseOnlySourceFence();
    }

    private function restoreServiceMutationPredecessorAuthorities(): void
    {
        foreach ([
            'service-insert-guard.sql',
            'history-insert-guard.sql',
            'history-after-insert.sql',
            'operation-update-guard.sql',
            'service-update-guard.sql',
            'operation-insert-guard.sql',
        ] as $file) {
            $path = database_path('sql/service-mutation-authority/'.$file);
            $sql = file_get_contents($path);
            if (! is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('Service mutation predecessor authority SQL asset is unavailable: '.$file);
            }

            DB::connection()->getPdo()->exec($sql);
        }
    }

    private function replaceOrderSourceConstraints(): void
    {
        $this->replaceConstraint(
            'orders',
            'orders_source_type_chk',
            "CHECK (`source_type` IN ('purchase','trial','gift','service_code','benefit_code','admin_grant'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (
        `source_type` NOT IN ('purchase','trial','gift','service_code','benefit_code','admin_grant')
        OR BINARY `source_type` IN (
            BINARY 'purchase', BINARY 'trial', BINARY 'gift', BINARY 'service_code',
            BINARY 'benefit_code', BINARY 'admin_grant'
        )
    )
    AND (
        `state` NOT IN ('authorized','paid','provisioning_queued')
        OR BINARY `state` IN (BINARY 'authorized', BINARY 'paid', BINARY 'provisioning_queued')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );
    }

    private function replaceOrderInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_purchase_count INT DEFAULT 0;
    DECLARE valid_source_count INT DEFAULT 0;

    IF NEW.source_type = 'purchase'
       AND NEW.state = 'awaiting_payment'
       AND NEW.state_version = 0 THEN
        IF NEW.order_source_authorization_id IS NOT NULL
           OR NEW.order_source_authorization_public_id IS NOT NULL
           OR NEW.purchase_settlement_id IS NOT NULL
           OR NEW.purchase_settlement_public_id IS NOT NULL
           OR NEW.payment_intent_id IS NOT NULL
           OR NEW.payment_intent_public_id IS NOT NULL
           OR NEW.settled_amount_irr IS NOT NULL
           OR NEW.paid_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-payment Order cannot claim captured or source authorization authority.';
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
        IF NEW.order_source_authorization_id IS NOT NULL
           OR NEW.order_source_authorization_public_id IS NOT NULL
           OR NEW.state <> 'paid'
           OR NEW.state_version <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Settlement-backed Order insertion is restricted to paid/v1 purchase authority.';
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

        IF valid_purchase_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Paid Order requires one matching authoritative purchase settlement.';
        END IF;
    ELSEIF NEW.source_type IN ('trial','benefit_code','admin_grant') THEN
        IF NEW.state <> 'authorized'
           OR NEW.state_version <> 0
           OR NEW.purchase_settlement_id IS NOT NULL
           OR NEW.purchase_settlement_public_id IS NOT NULL
           OR NEW.payment_intent_id IS NOT NULL
           OR NEW.payment_intent_public_id IS NOT NULL
           OR NEW.source_quote_id IS NOT NULL
           OR NEW.source_quote_public_id IS NOT NULL
           OR NEW.source_quote_configuration_hash IS NOT NULL
           OR NEW.settled_amount_irr IS NOT NULL
           OR NEW.paid_at IS NOT NULL
           OR NEW.total_amount_irr <> 0
           OR NEW.currency <> 'IRR'
           OR NEW.order_source_authorization_id IS NULL
           OR NEW.order_source_authorization_public_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Source-authorized Order must start as exact zero-cost authorized/v0 authority.';
        END IF;

        SELECT COUNT(*) INTO valid_source_count
        FROM order_source_authorizations authorization_row
        WHERE authorization_row.id = NEW.order_source_authorization_id
          AND authorization_row.public_id = NEW.order_source_authorization_public_id
          AND authorization_row.source_type = NEW.source_type
          AND authorization_row.user_id = NEW.user_id;

        IF valid_source_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zero-cost Order requires one exact immutable source authorization.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported Order source type.';
    END IF;
END
SQL);
    }

    private function replaceOrderItemInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_items_insert_guard
BEFORE INSERT ON order_items
FOR EACH ROW
BEGIN
    DECLARE source_type_value VARCHAR(32) DEFAULT NULL;
    DECLARE valid_quote_count INT DEFAULT 0;
    DECLARE valid_source_count INT DEFAULT 0;

    SELECT source_type INTO source_type_value
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF source_type_value = 'purchase' THEN
        IF NEW.order_source_authorization_id IS NOT NULL
           OR NEW.order_source_authorization_public_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase Order Item cannot claim source authorization authority.';
        END IF;

        SELECT COUNT(*) INTO valid_quote_count
        FROM orders order_row
        INNER JOIN quotes quote_row ON quote_row.id = NEW.source_quote_id
        WHERE order_row.id = NEW.order_id
          AND order_row.source_type = 'purchase'
          AND order_row.source_quote_id = NEW.source_quote_id
          AND order_row.source_quote_public_id = NEW.source_quote_public_id
          AND order_row.source_quote_configuration_hash = NEW.configuration_snapshot_hash
          AND NEW.line_number = 1
          AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.account_type_snapshot = NEW.account_type_snapshot
          AND quote_row.plan_offering_id = NEW.plan_offering_id
          AND quote_row.offering_code_snapshot = NEW.offering_code_snapshot
          AND quote_row.offering_version = NEW.offering_version
          AND quote_row.offering_configuration_hash = NEW.offering_configuration_hash
          AND quote_row.base_price_irr = NEW.base_price_irr
          AND quote_row.override_source = NEW.override_source
          AND (quote_row.override_reference_code <=> NEW.override_reference_code)
          AND (quote_row.override_price_irr <=> NEW.override_price_irr)
          AND quote_row.effective_price_irr = NEW.effective_price_irr
          AND (quote_row.discount_reference_code <=> NEW.discount_reference_code)
          AND quote_row.discount_irr = NEW.discount_irr
          AND quote_row.final_price_irr = NEW.final_price_irr
          AND quote_row.currency = NEW.currency
          AND quote_row.configuration_snapshot_hash = NEW.configuration_snapshot_hash
          AND LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) = LOWER(NEW.configuration_snapshot_hash);

        IF valid_quote_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order Item must match its immutable source Quote snapshot.';
        END IF;
    ELSEIF source_type_value IN ('trial','benefit_code','admin_grant') THEN
        SELECT COUNT(*) INTO valid_source_count
        FROM orders order_row
        INNER JOIN order_source_authorizations authorization_row
            ON authorization_row.id = order_row.order_source_authorization_id
        INNER JOIN users user_row ON user_row.id = order_row.user_id
        INNER JOIN plan_offerings offering_row ON offering_row.id = authorization_row.plan_offering_id
        INNER JOIN plan_offering_histories offering_history_row
            ON offering_history_row.plan_offering_id = offering_row.id
           AND offering_history_row.version = offering_row.version
        WHERE order_row.id = NEW.order_id
          AND order_row.source_type = authorization_row.source_type
          AND order_row.source_type = source_type_value
          AND order_row.state = 'authorized'
          AND order_row.state_version = 0
          AND order_row.order_source_authorization_public_id = authorization_row.public_id
          AND authorization_row.user_id = order_row.user_id
          AND NEW.line_number = 1
          AND NEW.source_quote_id IS NULL
          AND NEW.source_quote_public_id IS NULL
          AND NEW.order_source_authorization_id = authorization_row.id
          AND NEW.order_source_authorization_public_id = authorization_row.public_id
          AND NEW.account_type_snapshot = user_row.account_type
          AND NEW.plan_offering_id = authorization_row.plan_offering_id
          AND NEW.plan_offering_id = offering_row.id
          AND NEW.offering_code_snapshot = offering_row.code
          AND NEW.offering_version = offering_row.version
          AND NEW.offering_configuration_hash = offering_history_row.to_configuration_hash
          AND NEW.base_price_irr = offering_row.base_price_irr
          AND NEW.override_source = 'source'
          AND NEW.override_reference_code = authorization_row.source_type
          AND NEW.override_price_irr = 0
          AND NEW.effective_price_irr = 0
          AND NEW.discount_reference_code IS NULL
          AND NEW.discount_irr = 0
          AND NEW.final_price_irr = 0
          AND NEW.currency = 'IRR'
          AND CAST(NEW.configuration_snapshot AS CHAR) = CAST(authorization_row.configuration_snapshot AS CHAR)
          AND NEW.configuration_snapshot_hash = authorization_row.configuration_snapshot_hash
          AND LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) = LOWER(NEW.configuration_snapshot_hash);

        IF valid_source_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zero-cost Order Item must match its immutable source authorization and Plan Offering snapshot.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order Item parent has unsupported source authority.';
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
    ELSEIF NEW.source_type IN ('trial','benefit_code','admin_grant')
       AND NEW.state = 'authorized' AND NEW.state_version = 0 THEN
        INSERT INTO order_state_histories (
            order_id, from_state, to_state, from_version, to_version, actor_type, actor_id,
            reason_code, correlation_id, created_at
        ) VALUES (
            NEW.id, NULL, NEW.state, NULL, NEW.state_version, 'system', NULL,
            'authoritative_source_authorization', NEW.creation_correlation_id, NEW.created_at
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
          AND payment_intent_id IS NULL
          AND order_source_authorization_id IS NULL;
    ELSEIF NEW.from_state IS NULL
       AND NEW.from_version IS NULL
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
          AND payment_intent_id IS NOT NULL
          AND order_source_authorization_id IS NULL;
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
          AND payment_intent_id IS NOT NULL
          AND order_source_authorization_id IS NULL;
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
          AND order_row.source_type = 'purchase'
          AND order_row.state = 'provisioning_queued'
          AND order_row.state_version = 2
          AND operation_row.state = 'queued'
          AND operation_row.state_version = 1
          AND operation_row.correlation_id = NEW.correlation_id;
    ELSEIF NEW.from_state IS NULL
       AND NEW.from_version IS NULL
       AND NEW.to_state = 'authorized'
       AND NEW.to_version = 0
       AND NEW.actor_type = 'system'
       AND NEW.actor_id IS NULL
       AND NEW.reason_code = 'authoritative_source_authorization' THEN
        SELECT COUNT(*) INTO valid_transition_count
        FROM orders order_row
        INNER JOIN order_source_authorizations authorization_row
            ON authorization_row.id = order_row.order_source_authorization_id
        WHERE order_row.id = NEW.order_id
          AND order_row.source_type IN ('trial','benefit_code','admin_grant')
          AND order_row.source_type = authorization_row.source_type
          AND order_row.order_source_authorization_public_id = authorization_row.public_id
          AND order_row.state = 'authorized'
          AND order_row.state_version = 0
          AND order_row.total_amount_irr = 0
          AND order_row.purchase_settlement_id IS NULL
          AND order_row.payment_intent_id IS NULL;
    ELSEIF NEW.from_state = 'authorized'
       AND NEW.from_version = 0
       AND NEW.to_state = 'provisioning_queued'
       AND NEW.to_version = 1
       AND NEW.actor_type = 'system'
       AND NEW.actor_id IS NULL
       AND NEW.reason_code = 'initial_provisioning_queued' THEN
        SELECT COUNT(*) INTO valid_transition_count
        FROM orders order_row
        INNER JOIN provisioning_operations operation_row
            ON operation_row.order_id = order_row.id AND operation_row.operation_type = 'initial_provision'
        WHERE order_row.id = NEW.order_id
          AND order_row.source_type IN ('trial','benefit_code','admin_grant')
          AND order_row.state = 'provisioning_queued'
          AND order_row.state_version = 1
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

    private function replaceServiceInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_insert_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE source_type_value VARCHAR(32) DEFAULT NULL;
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_source_authorization_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_authority_order_id BIGINT UNSIGNED DEFAULT NULL;

    IF NEW.route_selection_id IS NOT NULL
       OR NEW.service_target_id IS NOT NULL
       OR NEW.remote_service_id IS NOT NULL
       OR NEW.provisioned_at IS NOT NULL
       OR NEW.lifecycle_state <> 'active'
       OR NEW.lifecycle_version <> 0
       OR NEW.remote_identity_generation <> 1
       OR NEW.mutation_generation <> 0
       OR NEW.remote_deleted_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription must start with a clean local lifecycle and no remote binding.';
    END IF;

    SELECT source_type, purchase_settlement_id, payment_intent_id, order_source_authorization_id
      INTO source_type_value, authority_settlement_id, authority_intent_id, locked_source_authorization_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF source_type_value = 'purchase' THEN
        IF authority_settlement_id IS NULL OR authority_intent_id IS NULL OR locked_source_authorization_id IS NOT NULL THEN
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
          AND order_row.order_source_authorization_id IS NULL
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
    ELSEIF source_type_value IN ('trial','benefit_code','admin_grant') THEN
        IF authority_settlement_id IS NOT NULL OR authority_intent_id IS NOT NULL OR locked_source_authorization_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription zero-cost source authority shape is invalid.';
        END IF;

        SELECT id INTO locked_source_authorization_id
        FROM order_source_authorizations
        WHERE id = locked_source_authorization_id
        LIMIT 1
        FOR UPDATE;

        SELECT order_row.id INTO valid_authority_order_id
        FROM orders order_row
        INNER JOIN order_source_authorizations authorization_row
            ON authorization_row.id = order_row.order_source_authorization_id
        INNER JOIN order_items item_row ON item_row.id = NEW.order_item_id
        WHERE order_row.id = NEW.order_id
          AND authorization_row.id = locked_source_authorization_id
          AND order_row.source_type = authorization_row.source_type
          AND order_row.source_type = source_type_value
          AND order_row.order_source_authorization_public_id = authorization_row.public_id
          AND authorization_row.user_id = order_row.user_id
          AND item_row.order_id = order_row.id
          AND item_row.line_number = 1
          AND item_row.order_source_authorization_id = authorization_row.id
          AND item_row.order_source_authorization_public_id = authorization_row.public_id
          AND item_row.plan_offering_id = authorization_row.plan_offering_id
          AND item_row.configuration_snapshot_hash = authorization_row.configuration_snapshot_hash
          AND order_row.user_id = NEW.user_id
          AND order_row.state = 'authorized'
          AND order_row.state_version = 0
          AND order_row.total_amount_irr = 0
          AND order_row.settled_amount_irr IS NULL
          AND order_row.paid_at IS NULL
        LIMIT 1
        FOR UPDATE;
    END IF;

    IF valid_authority_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires one exact currently valid Order Item authority.';
    END IF;
END
SQL);
    }

    private function replaceProvisioningOperationInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_insert_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE source_type_value VARCHAR(32) DEFAULT NULL;
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_source_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_source_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_authority_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_mutation_service_id BIGINT UNSIGNED DEFAULT NULL;

    IF NEW.operation_type = 'initial_provision' THEN
        IF NEW.attempt_count <> 0
           OR NEW.effect_fence_key IS NOT NULL
           OR NEW.route_hold_expires_at IS NOT NULL
           OR NEW.route_selection_id IS NOT NULL
           OR NEW.service_target_id IS NOT NULL
           OR NEW.capacity_reservation_id IS NOT NULL
           OR NEW.capacity_reservation_key IS NOT NULL
           OR NEW.remote_username IS NOT NULL
           OR NEW.target_reference IS NOT NULL
           OR NEW.last_result_code IS NOT NULL
           OR NEW.last_result_message IS NOT NULL
           OR NEW.remote_service_id IS NOT NULL
           OR NEW.remote_effect_started_at IS NOT NULL
           OR NEW.remote_effect_completed_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation cannot be created with remote-effect evidence.';
        END IF;

        SELECT source_type, purchase_settlement_id, payment_intent_id, order_source_authorization_id
          INTO source_type_value, authority_settlement_id, authority_intent_id, authority_source_id
        FROM orders
        WHERE id = NEW.order_id
        LIMIT 1;

        IF source_type_value = 'purchase' THEN
            IF authority_settlement_id IS NULL OR authority_intent_id IS NULL OR authority_source_id IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation purchase authority shape is invalid.';
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
              AND BINARY NEW.correlation_id = BINARY service_row.creation_correlation_id
              AND NEW.user_id = order_row.user_id
              AND NEW.operation_generation = 0
              AND NEW.target_remote_identity_generation = 0
              AND NEW.target_lifecycle_version = 0
              AND NEW.request_key_hash IS NULL
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
        ELSEIF source_type_value IN ('trial','benefit_code','admin_grant') THEN
            IF authority_settlement_id IS NOT NULL OR authority_intent_id IS NOT NULL OR authority_source_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation zero-cost authority shape is invalid.';
            END IF;

            SELECT id INTO locked_source_id
            FROM order_source_authorizations
            WHERE id = authority_source_id
            LIMIT 1
            FOR UPDATE;

            SELECT order_row.id INTO valid_authority_order_id
            FROM orders order_row
            INNER JOIN order_source_authorizations authorization_row
                ON authorization_row.id = order_row.order_source_authorization_id
            INNER JOIN order_items item_row ON item_row.id = NEW.order_item_id
            INNER JOIN service_subscriptions service_row ON service_row.id = NEW.service_subscription_id
            WHERE order_row.id = NEW.order_id
              AND authorization_row.id = locked_source_id
              AND order_row.source_type = authorization_row.source_type
              AND order_row.source_type = source_type_value
              AND order_row.order_source_authorization_public_id = authorization_row.public_id
              AND authorization_row.user_id = order_row.user_id
              AND item_row.order_id = order_row.id
              AND item_row.order_source_authorization_id = authorization_row.id
              AND item_row.order_source_authorization_public_id = authorization_row.public_id
              AND item_row.plan_offering_id = authorization_row.plan_offering_id
              AND item_row.configuration_snapshot_hash = authorization_row.configuration_snapshot_hash
              AND service_row.order_id = order_row.id
              AND service_row.order_item_id = item_row.id
              AND service_row.user_id = order_row.user_id
              AND BINARY NEW.correlation_id = BINARY service_row.creation_correlation_id
              AND NEW.user_id = order_row.user_id
              AND NEW.operation_generation = 0
              AND NEW.target_remote_identity_generation = 0
              AND NEW.target_lifecycle_version = 0
              AND NEW.request_key_hash IS NULL
              AND NEW.operation_key = CONCAT('initial-provision:', item_row.public_id)
              AND NEW.state = 'queued'
              AND NEW.state_version = 1
              AND order_row.state = 'authorized'
              AND order_row.state_version = 0
              AND order_row.total_amount_irr = 0
              AND order_row.settled_amount_irr IS NULL
              AND order_row.paid_at IS NULL
            LIMIT 1
            FOR UPDATE;
        END IF;

        IF valid_authority_order_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires one exact Order and Service authority.';
        END IF;
    ELSE
        IF COALESCE(@app_service_mutation_authority, '') <> 'service_mutation_queue_v1'
           OR NEW.operation_type NOT IN ('reset_usage','suspend','activate','delete','rotate_subscription_link')
           OR NEW.operation_generation < 1
           OR NEW.operation_generation <> COALESCE(@app_service_mutation_generation, 0)
           OR NEW.target_remote_identity_generation < 1
           OR NEW.request_key_hash IS NULL
           OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_service_mutation_request_hash, '')
           OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_mutation_correlation_id, '')
           OR NEW.state <> 'queued'
           OR NEW.state_version <> 1
           OR NEW.attempt_count <> 0
           OR NEW.effect_fence_key IS NOT NULL
           OR NEW.route_hold_expires_at IS NOT NULL
           OR NEW.route_selection_id IS NOT NULL
           OR NEW.capacity_reservation_id IS NOT NULL
           OR NEW.capacity_reservation_key IS NOT NULL
           OR NEW.remote_username IS NOT NULL
           OR NEW.target_reference IS NOT NULL
           OR NEW.remote_effect_started_at IS NOT NULL
           OR NEW.remote_effect_completed_at IS NOT NULL
           OR NEW.last_result_code IS NOT NULL
           OR NEW.last_result_message IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation operation creation authority is invalid.';
        END IF;

        SELECT service_row.id INTO valid_mutation_service_id
        FROM service_subscriptions service_row
        INNER JOIN order_items item_row ON item_row.id = service_row.order_item_id
        WHERE service_row.id = NEW.service_subscription_id
          AND service_row.order_id = NEW.order_id
          AND item_row.id = NEW.order_item_id
          AND item_row.order_id = NEW.order_id
          AND service_row.user_id = NEW.user_id
          AND service_row.mutation_generation = NEW.operation_generation
          AND service_row.remote_deleted_at IS NULL
          AND service_row.lifecycle_state IN ('active','suspended')
          AND (NEW.operation_type <> 'suspend' OR service_row.lifecycle_state = 'active')
          AND (NEW.operation_type <> 'activate' OR service_row.lifecycle_state = 'suspended')
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.service_target_id IS NOT NULL
          AND service_row.remote_service_id IS NOT NULL
          AND NEW.service_target_id = service_row.service_target_id
          AND BINARY NEW.remote_service_id = BINARY service_row.remote_service_id
          AND NEW.target_remote_identity_generation = service_row.remote_identity_generation
          AND NEW.target_lifecycle_version = service_row.lifecycle_version
          AND BINARY NEW.operation_key = BINARY CONCAT('service-mutation:', service_row.public_id, ':', NEW.operation_generation, ':', NEW.operation_type)
        LIMIT 1 FOR UPDATE;

        IF valid_mutation_service_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation operation does not match current Service generation, lifecycle, and remote binding.';
        END IF;
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
           OR NOT (OLD.order_source_authorization_id <=> NEW.order_source_authorization_id)
           OR NOT (OLD.order_source_authorization_public_id <=> NEW.order_source_authorization_public_id)
           OR NOT (OLD.total_amount_irr <=> NEW.total_amount_irr)
           OR NOT (OLD.currency <=> NEW.currency)
           OR NOT (OLD.creation_correlation_id <=> NEW.creation_correlation_id)
           OR NOT (OLD.created_at <=> NEW.created_at)
           OR OLD.order_source_authorization_id IS NOT NULL
           OR OLD.order_source_authorization_public_id IS NOT NULL
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
           OR NOT (OLD.order_source_authorization_id <=> NEW.order_source_authorization_id)
           OR NOT (OLD.order_source_authorization_public_id <=> NEW.order_source_authorization_public_id)
           OR NOT (OLD.total_amount_irr <=> NEW.total_amount_irr)
           OR NOT (OLD.settled_amount_irr <=> NEW.settled_amount_irr)
           OR NOT (OLD.currency <=> NEW.currency)
           OR NOT (OLD.paid_at <=> NEW.paid_at)
           OR NOT (OLD.creation_correlation_id <=> NEW.creation_correlation_id)
           OR NOT (OLD.created_at <=> NEW.created_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order commercial and authority identity is immutable.';
        END IF;

        IF OLD.source_type = 'purchase' THEN
            IF OLD.state <> 'paid'
               OR OLD.state_version <> 1
               OR NEW.state <> 'provisioning_queued'
               OR NEW.state_version <> 2
               OR OLD.order_source_authorization_id IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase Order lifecycle only permits paid/v1 to provisioning_queued/v2 here.';
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
        ELSEIF OLD.source_type IN ('trial','benefit_code','admin_grant') THEN
            IF OLD.state <> 'authorized'
               OR OLD.state_version <> 0
               OR NEW.state <> 'provisioning_queued'
               OR NEW.state_version <> 1
               OR OLD.order_source_authorization_id IS NULL
               OR OLD.purchase_settlement_id IS NOT NULL
               OR OLD.payment_intent_id IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zero-cost Order lifecycle only permits authorized/v0 to provisioning_queued/v1 here.';
            END IF;

            SELECT operation_row.id INTO valid_queue_operation_id
            FROM order_source_authorizations authorization_row
            INNER JOIN order_items item_row
                ON item_row.order_id = OLD.id
               AND item_row.line_number = 1
               AND item_row.order_source_authorization_id = authorization_row.id
               AND item_row.order_source_authorization_public_id = authorization_row.public_id
            INNER JOIN service_subscriptions service_row
                ON service_row.order_id = OLD.id
               AND service_row.order_item_id = item_row.id
               AND service_row.user_id = OLD.user_id
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
            WHERE authorization_row.id = OLD.order_source_authorization_id
              AND authorization_row.public_id = OLD.order_source_authorization_public_id
              AND authorization_row.source_type = OLD.source_type
              AND authorization_row.user_id = OLD.user_id
              AND item_row.plan_offering_id = authorization_row.plan_offering_id
              AND item_row.configuration_snapshot_hash = authorization_row.configuration_snapshot_hash
              AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.order_public_id')) = OLD.public_id
              AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.order_item_public_id')) = item_row.public_id
              AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.service_subscription_public_id')) = service_row.public_id
              AND JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.provisioning_operation_public_id')) = operation_row.public_id
              AND LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)) = LOWER(outbox_row.payload_hash)
            LIMIT 1;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source has no enabled lifecycle mutation authority.';
        END IF;

        IF valid_queue_operation_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order provisioning queue transition requires exact durable source authority and one matching Outbox command.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_provisioning_history
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE operation_correlation VARCHAR(64);

    IF (OLD.source_type = 'purchase'
        AND OLD.state = 'paid' AND OLD.state_version = 1
        AND NEW.state = 'provisioning_queued' AND NEW.state_version = 2)
       OR (OLD.source_type IN ('trial','benefit_code','admin_grant')
        AND OLD.state = 'authorized' AND OLD.state_version = 0
        AND NEW.state = 'provisioning_queued' AND NEW.state_version = 1) THEN
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

    private function replaceProvisioningOutboxEnvelopeGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_provisioning_outbox_envelope_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE valid_outbox_count INT DEFAULT 0;

    IF (OLD.source_type = 'purchase'
        AND OLD.state = 'paid' AND OLD.state_version = 1
        AND NEW.state = 'provisioning_queued' AND NEW.state_version = 2)
       OR (OLD.source_type IN ('trial','benefit_code','admin_grant')
        AND OLD.state = 'authorized' AND OLD.state_version = 0
        AND NEW.state = 'provisioning_queued' AND NEW.state_version = 1) THEN
        SELECT COUNT(*) INTO valid_outbox_count
        FROM order_items item_row
        INNER JOIN service_subscriptions service_row
            ON service_row.order_id = OLD.id
           AND service_row.order_item_id = item_row.id
           AND service_row.user_id = OLD.user_id
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
            ON HEX(outbox_row.event_key) = HEX(CONCAT('provisioning.initial.requested:', operation_row.public_id))
           AND HEX(outbox_row.event_type) = HEX('provisioning.initial.requested')
           AND HEX(outbox_row.aggregate_type) = HEX('provisioning_operation')
           AND HEX(outbox_row.aggregate_id) = HEX(operation_row.public_id)
           AND HEX(outbox_row.correlation_id) = HEX(operation_row.correlation_id)
           AND outbox_row.dispatch_state = 'authority_pending'
        WHERE item_row.order_id = OLD.id
          AND item_row.line_number = 1
          AND HEX(CAST(outbox_row.payload AS CHAR)) = HEX(CONCAT(
                '{"order_item_public_id":"', item_row.public_id,
                '","order_public_id":"', OLD.public_id,
                '","provisioning_operation_public_id":"', operation_row.public_id,
                '","service_subscription_public_id":"', service_row.public_id,
                '"}'
          ))
          AND HEX(outbox_row.payload_hash) = HEX(LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)));

        IF valid_outbox_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order provisioning queue transition requires one exact canonical safe Outbox command.';
        END IF;
    END IF;
END
SQL);
    }

    private function replaceOutboxReleaseGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_initial_provision_envelope_update_guard
BEFORE UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    DECLARE final_authority_count INT DEFAULT 0;

    IF HEX(OLD.event_type) = HEX('provisioning.initial.requested') THEN
        IF HEX(OLD.id) <> HEX(NEW.id)
           OR HEX(OLD.event_key) <> HEX(NEW.event_key)
           OR HEX(OLD.event_type) <> HEX(NEW.event_type)
           OR HEX(OLD.aggregate_type) <> HEX(NEW.aggregate_type)
           OR HEX(OLD.aggregate_id) <> HEX(NEW.aggregate_id)
           OR HEX(CAST(OLD.payload AS CHAR)) <> HEX(CAST(NEW.payload AS CHAR))
           OR HEX(OLD.payload_hash) <> HEX(NEW.payload_hash)
           OR HEX(OLD.correlation_id) <> HEX(NEW.correlation_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox command identity is immutable.';
        END IF;

        IF HEX(NEW.dispatch_state) NOT IN (
            HEX('authority_pending'), HEX('pending'), HEX('leased'), HEX('retry'), HEX('processed'), HEX('review_required')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox must use an exact dispatch lifecycle state.';
        END IF;

        IF OLD.dispatch_state = 'authority_pending' AND NEW.dispatch_state <> 'authority_pending' THEN
            IF HEX(NEW.dispatch_state) <> HEX('pending')
               OR NEW.processed_at IS NOT NULL
               OR NEW.lease_token IS NOT NULL
               OR NEW.leased_until IS NOT NULL
               OR NEW.attempts <> 0
               OR NEW.review_reason IS NOT NULL
               OR NEW.last_error_class IS NOT NULL
               OR NEW.last_error_code IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox authority can only release into a clean pending dispatch state.';
            END IF;

            SELECT COUNT(*) INTO final_authority_count
            FROM orders order_row
            INNER JOIN order_items item_row
                ON item_row.order_id = order_row.id
               AND item_row.line_number = 1
            INNER JOIN service_subscriptions service_row
                ON service_row.order_id = order_row.id
               AND service_row.order_item_id = item_row.id
               AND service_row.user_id = order_row.user_id
            INNER JOIN provisioning_operations operation_row
                ON operation_row.order_id = order_row.id
               AND operation_row.order_item_id = item_row.id
               AND operation_row.service_subscription_id = service_row.id
               AND operation_row.user_id = order_row.user_id
               AND operation_row.operation_type = 'initial_provision'
               AND operation_row.operation_key = CONCAT('initial-provision:', item_row.public_id)
               AND operation_row.state = 'queued'
               AND operation_row.state_version = 1
            LEFT JOIN order_source_authorizations authorization_row
                ON authorization_row.id = order_row.order_source_authorization_id
            WHERE order_row.state = 'provisioning_queued'
              AND (
                  (order_row.source_type = 'purchase'
                   AND order_row.state_version = 2
                   AND order_row.order_source_authorization_id IS NULL)
                  OR
                  (order_row.source_type IN ('trial','benefit_code','admin_grant')
                   AND order_row.state_version = 1
                   AND authorization_row.id IS NOT NULL
                   AND authorization_row.public_id = order_row.order_source_authorization_public_id
                   AND authorization_row.source_type = order_row.source_type
                   AND authorization_row.user_id = order_row.user_id
                   AND item_row.order_source_authorization_id = authorization_row.id
                   AND item_row.order_source_authorization_public_id = authorization_row.public_id)
              )
              AND HEX(order_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_public_id')))
              AND HEX(item_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.order_item_public_id')))
              AND HEX(service_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.service_subscription_public_id')))
              AND HEX(operation_row.public_id) = HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.provisioning_operation_public_id')))
              AND HEX(operation_row.public_id) = HEX(NEW.aggregate_id)
              AND HEX(operation_row.correlation_id) = HEX(NEW.correlation_id);

            IF final_authority_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox dispatch requires the exact final queued local authority.';
            END IF;
        ELSEIF OLD.dispatch_state <> 'authority_pending' AND NEW.dispatch_state = 'authority_pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial provisioning Outbox dispatch lifecycle cannot return to authority_pending.';
        END IF;
    ELSEIF LOWER(NEW.event_type) = 'provisioning.initial.requested' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Outbox rows cannot be converted into initial provisioning commands.';
    END IF;
END
SQL);
    }

    private function activateSupportedSourceFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_unimplemented_source_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    IF HEX(NEW.source_type) NOT IN (
        HEX('purchase'), HEX('trial'), HEX('benefit_code'), HEX('admin_grant')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source type has no active creation authority.';
    END IF;
END
SQL);
    }

    private function restorePurchaseOnlySourceFence(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_unimplemented_source_insert_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    IF NEW.source_type <> 'purchase' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source type has no active creation authority.';
    END IF;
END
SQL);
    }

    private function restorePurchaseOrderItemInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER order_items_insert_guard
BEFORE INSERT ON order_items
FOR EACH ROW
BEGIN
    DECLARE valid_quote_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_quote_count
    FROM orders order_row
    INNER JOIN quotes quote_row ON quote_row.id = NEW.source_quote_id
    WHERE order_row.id = NEW.order_id
      AND order_row.source_type = 'purchase'
      AND order_row.source_quote_id = NEW.source_quote_id
      AND order_row.source_quote_public_id = NEW.source_quote_public_id
      AND order_row.source_quote_configuration_hash = NEW.configuration_snapshot_hash
      AND NEW.line_number = 1
      AND quote_row.public_id = NEW.source_quote_public_id
      AND quote_row.account_type_snapshot = NEW.account_type_snapshot
      AND quote_row.plan_offering_id = NEW.plan_offering_id
      AND quote_row.offering_code_snapshot = NEW.offering_code_snapshot
      AND quote_row.offering_version = NEW.offering_version
      AND quote_row.offering_configuration_hash = NEW.offering_configuration_hash
      AND quote_row.base_price_irr = NEW.base_price_irr
      AND quote_row.override_source = NEW.override_source
      AND (quote_row.override_reference_code <=> NEW.override_reference_code)
      AND (quote_row.override_price_irr <=> NEW.override_price_irr)
      AND quote_row.effective_price_irr = NEW.effective_price_irr
      AND (quote_row.discount_reference_code <=> NEW.discount_reference_code)
      AND quote_row.discount_irr = NEW.discount_irr
      AND quote_row.final_price_irr = NEW.final_price_irr
      AND quote_row.currency = NEW.currency
      AND quote_row.configuration_snapshot_hash = NEW.configuration_snapshot_hash
      AND LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) = LOWER(NEW.configuration_snapshot_hash);

    IF valid_quote_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order Item must match its immutable source Quote snapshot.';
    END IF;
END
SQL);
    }

    private function assertAuthorityReady(): void
    {
        $triggerRow = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN (
      'orders_insert_guard',
      'order_items_insert_guard',
      'orders_initial_history',
      'order_state_histories_insert_guard',
      'service_subscriptions_insert_guard',
      'service_subscriptions_update_guard',
      'provisioning_operations_insert_guard',
      'provisioning_operations_update_guard',
      'provisioning_operation_histories_insert_guard',
      'provisioning_operation_initial_history',
      'provisioning_remote_effect_events_insert_guard',
      'orders_update_guard',
      'orders_provisioning_history',
      'orders_provisioning_outbox_envelope_guard',
      'outbox_initial_provision_envelope_update_guard',
      'orders_unimplemented_source_insert_guard'
  )
SQL);

        if ($triggerRow === null || (int) $triggerRow->aggregate !== 16
            || ! $this->constraintExists('orders', 'orders_source_type_chk')
            || ! $this->constraintExists('orders', 'orders_provisioning_exact_authority_chk')
            || ! $this->triggerContains('service_subscriptions_insert_guard', 'zero-cost source authority shape is invalid')
            || ! $this->triggerContains('service_subscriptions_insert_guard', 'clean local lifecycle and no remote binding')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_mutation_queue_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_mutation_effect_v1')
            || ! $this->triggerContains('provisioning_operations_insert_guard', 'Initial Provisioning Operation zero-cost authority shape is invalid')
            || ! $this->triggerContains('provisioning_operations_insert_guard', 'service_mutation_queue_v1')
            || ! $this->triggerContains('provisioning_operations_update_guard', 'initial_remote_effect_v1')
            || ! $this->triggerContains('provisioning_operations_update_guard', 'service_mutation_effect_v1')
            || ! $this->triggerContains('provisioning_operations_update_guard', 'recovery_transition')
            || ! $this->triggerContains('provisioning_operation_histories_insert_guard', 'service_mutation_requested')
            || ! $this->triggerContains('provisioning_operation_initial_history', 'service_mutation_requested')
            || ! $this->triggerContains('provisioning_remote_effect_events_insert_guard', 'service_mutation_effect_v1')) {
            throw new RuntimeException('Non-paid Order authority activation prerequisites are incomplete.');
        }
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

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
