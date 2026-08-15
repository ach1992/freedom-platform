<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        // Keep queue creation fail-closed until the complete monotonic invalidation chain exists.
        // Every step is restart-safe because MariaDB may commit DDL statements independently.
        $this->createFailClosedQueueFenceGuards();

        if (! Schema::hasTable('provisioning_financial_invalidations')) {
            Schema::create('provisioning_financial_invalidations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('order_id')->nullable()->unique('prov_fin_inv_order_unique');
                $table->foreignId('purchase_settlement_id')->unique('prov_fin_inv_settlement_unique');
                $table->foreignId('payment_intent_id')->unique('prov_fin_inv_intent_unique');
                $table->foreignId('purchase_refund_id')->unique('prov_fin_inv_refund_unique');
                $table->dateTime('created_at', 6);

                $table->foreign('order_id', 'prov_fin_inv_order_fk')
                    ->references('id')->on('orders')->restrictOnDelete();
                $table->foreign('purchase_settlement_id', 'prov_fin_inv_settlement_fk')
                    ->references('id')->on('purchase_settlements')->restrictOnDelete();
                $table->foreign('payment_intent_id', 'prov_fin_inv_intent_fk')
                    ->references('id')->on('payment_intents')->restrictOnDelete();
                $table->foreign('purchase_refund_id', 'prov_fin_inv_refund_fk')
                    ->references('id')->on('purchase_refunds')->restrictOnDelete();
            });
        }

        $this->createInvalidationImmutabilityGuards();
        $this->createRefundInvalidationTrigger();
        $this->backfillExistingRefundInvalidations();
        $this->createPaymentIntentInvalidationGuard();
        $this->activateQueueFenceGuards();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_subscriptions') && DB::table('service_subscriptions')->exists()) {
            throw new RuntimeException('Cannot roll back provisioning financial invalidation while Service Subscriptions exist.');
        }
        if (DB::table('orders')->where('state', 'provisioning_queued')->exists()) {
            throw new RuntimeException('Cannot roll back provisioning financial invalidation while queued Orders exist.');
        }
        if (Schema::hasTable('provisioning_financial_invalidations') && DB::table('provisioning_financial_invalidations')->exists()) {
            throw new RuntimeException('Cannot roll back provisioning financial invalidation while durable refund invalidations exist.');
        }

        $this->createFailClosedQueueFenceGuards();
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_provisioning_invalidation_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_provisioning_invalidation');
        Schema::dropIfExists('provisioning_financial_invalidations');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_invalidation_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_financial_invalidation_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_subscriptions_financial_invalidation_guard');
    }

    private function createFailClosedQueueFenceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_financial_invalidation_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation migration is incomplete.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_financial_invalidation_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation migration is incomplete.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_provisioning_invalidation_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    IF OLD.state = 'paid'
       AND OLD.state_version = 1
       AND NEW.state = 'provisioning_queued'
       AND NEW.state_version = 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation migration is incomplete.';
    END IF;
END
SQL);
    }

    private function createInvalidationImmutabilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_financial_invalidations_update_guard
BEFORE UPDATE ON provisioning_financial_invalidations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_financial_invalidations_delete_guard
BEFORE DELETE ON provisioning_financial_invalidations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation is non-deletable.';
END
SQL);
    }

    private function createRefundInvalidationTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_refunds_provisioning_invalidation
AFTER INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE existing_invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    -- A refund can precede historical Order materialization. Keep the optional Order binding for
    -- evidence when it already exists, but make the durable revocation authoritative by the
    -- immutable settlement/payment pair so queue safety never depends on Order creation timing.
    SELECT id INTO locked_order_id
    FROM orders
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    -- The purchase-refund insert authority has already acquired settlement -> intent locks for this
    -- financial identity. Under those locks, exact duplicate/later refunds can safely converge on
    -- the first permanent invalidation without INSERT IGNORE or an UPDATE escape hatch. Only a
    -- structurally valid existing invalidation can satisfy the convergence check; a malformed row
    -- instead causes the following INSERT to fail closed on the unique financial fence.
    SELECT invalidation_row.id INTO existing_invalidation_id
    FROM provisioning_financial_invalidations invalidation_row
    INNER JOIN purchase_refunds refund_row
        ON refund_row.id = invalidation_row.purchase_refund_id
       AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
       AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
    WHERE invalidation_row.purchase_settlement_id = NEW.purchase_settlement_id
      AND invalidation_row.payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF existing_invalidation_id IS NULL THEN
        INSERT INTO provisioning_financial_invalidations (
            order_id, purchase_settlement_id, payment_intent_id, purchase_refund_id, created_at
        ) VALUES (
            locked_order_id, NEW.purchase_settlement_id, NEW.payment_intent_id, NEW.id, CURRENT_TIMESTAMP(6)
        );
    END IF;
END
SQL);
    }

    private function backfillExistingRefundInvalidations(): void
    {
        DB::statement(<<<'SQL'
INSERT INTO provisioning_financial_invalidations (
    order_id, purchase_settlement_id, payment_intent_id, purchase_refund_id, created_at
)
SELECT
    order_row.id,
    refund_chain.purchase_settlement_id,
    refund_chain.payment_intent_id,
    refund_chain.purchase_refund_id,
    CURRENT_TIMESTAMP(6)
FROM (
    SELECT
        purchase_settlement_id,
        payment_intent_id,
        MIN(id) AS purchase_refund_id
    FROM purchase_refunds
    GROUP BY purchase_settlement_id, payment_intent_id
) refund_chain
LEFT JOIN orders order_row
    ON order_row.purchase_settlement_id = refund_chain.purchase_settlement_id
   AND order_row.payment_intent_id = refund_chain.payment_intent_id
LEFT JOIN provisioning_financial_invalidations existing_invalidation
    ON existing_invalidation.purchase_settlement_id = refund_chain.purchase_settlement_id
   AND existing_invalidation.payment_intent_id = refund_chain.payment_intent_id
LEFT JOIN purchase_refunds existing_refund
    ON existing_refund.id = existing_invalidation.purchase_refund_id
   AND existing_refund.purchase_settlement_id = existing_invalidation.purchase_settlement_id
   AND existing_refund.payment_intent_id = existing_invalidation.payment_intent_id
WHERE existing_refund.id IS NULL
SQL);
    }

    private function createPaymentIntentInvalidationGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER payment_intents_provisioning_invalidation_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE valid_invalidation_count INT DEFAULT 0;

    IF NEW.purpose = 'purchase'
       AND OLD.state IN ('captured','partially_refunded')
       AND NEW.state = 'refund_pending' THEN
        -- Provisioning invalidation is monotonic per immutable purchase financial identity. The
        -- first accepted refund permanently revokes initial provisioning even if no Order exists yet.
        SELECT COUNT(*) INTO valid_invalidation_count
        FROM provisioning_financial_invalidations invalidation_row
        INNER JOIN purchase_settlements settlement_row
            ON settlement_row.id = invalidation_row.purchase_settlement_id
           AND settlement_row.payment_intent_id = invalidation_row.payment_intent_id
        INNER JOIN purchase_refunds refund_row
            ON refund_row.id = invalidation_row.purchase_refund_id
           AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
           AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
        WHERE invalidation_row.payment_intent_id = NEW.id;

        IF valid_invalidation_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund must invalidate provisioning authority before refund_pending transition.';
        END IF;
    END IF;
END
SQL);
    }

    private function activateQueueFenceGuards(): void
    {
        // Order transition owns the Order row and remains the final local queue linearization point.
        // The invalidation itself is keyed by immutable purchase financial identity because a valid
        // refund may have committed before this historical Order was materialized.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER orders_provisioning_invalidation_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    IF OLD.state = 'paid'
       AND OLD.state_version = 1
       AND NEW.state = 'provisioning_queued'
       AND NEW.state_version = 2 THEN
        SELECT invalidation_row.id INTO invalidation_id
        FROM provisioning_financial_invalidations invalidation_row
        INNER JOIN purchase_refunds refund_row
            ON refund_row.id = invalidation_row.purchase_refund_id
           AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
           AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
        WHERE invalidation_row.purchase_settlement_id = OLD.purchase_settlement_id
          AND invalidation_row.payment_intent_id = OLD.payment_intent_id
        LIMIT 1
        FOR UPDATE;

        IF invalidation_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot transition to provisioning_queued.';
        END IF;
    END IF;
END
SQL);

        // Each companion guard independently acquires the canonical financial locks before its
        // current invalidation read. This keeps correctness independent of same-event trigger order
        // while preserving settlement -> intent -> Order -> invalidation lock ordering.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_financial_invalidation_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT purchase_settlement_id, payment_intent_id
    INTO authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires authoritative purchase financial identity.';
    END IF;

    SELECT id INTO locked_settlement_id
    FROM purchase_settlements
    WHERE id = authority_settlement_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_intent_id
    FROM payment_intents
    WHERE id = authority_intent_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE id = NEW.order_id
      AND purchase_settlement_id = locked_settlement_id
      AND payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_settlement_id IS NULL OR locked_intent_id IS NULL OR locked_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation financial authority is unavailable.';
    END IF;

    SELECT invalidation_row.id INTO invalidation_id
    FROM provisioning_financial_invalidations invalidation_row
    INNER JOIN purchase_refunds refund_row
        ON refund_row.id = invalidation_row.purchase_refund_id
       AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
       AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
    WHERE invalidation_row.purchase_settlement_id = locked_settlement_id
      AND invalidation_row.payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF invalidation_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create an initial Provisioning Operation.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_financial_invalidation_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE authority_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE authority_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT purchase_settlement_id, payment_intent_id
    INTO authority_settlement_id, authority_intent_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1;

    IF authority_settlement_id IS NULL OR authority_intent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires authoritative purchase financial identity.';
    END IF;

    SELECT id INTO locked_settlement_id
    FROM purchase_settlements
    WHERE id = authority_settlement_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_intent_id
    FROM payment_intents
    WHERE id = authority_intent_id
    LIMIT 1
    FOR UPDATE;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE id = NEW.order_id
      AND purchase_settlement_id = locked_settlement_id
      AND payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_settlement_id IS NULL OR locked_intent_id IS NULL OR locked_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription financial authority is unavailable.';
    END IF;

    SELECT invalidation_row.id INTO invalidation_id
    FROM provisioning_financial_invalidations invalidation_row
    INNER JOIN purchase_refunds refund_row
        ON refund_row.id = invalidation_row.purchase_refund_id
       AND refund_row.purchase_settlement_id = invalidation_row.purchase_settlement_id
       AND refund_row.payment_intent_id = invalidation_row.payment_intent_id
    WHERE invalidation_row.purchase_settlement_id = locked_settlement_id
      AND invalidation_row.payment_intent_id = locked_intent_id
    LIMIT 1
    FOR UPDATE;

    IF invalidation_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create a Service Subscription.';
    END IF;
END
SQL);
    }
};
