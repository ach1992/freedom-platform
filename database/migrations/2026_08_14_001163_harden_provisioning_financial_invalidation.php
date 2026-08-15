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
        // MariaDB DDL commits per statement. Freeze new queue creation first so a partial
        // migration cannot expose an unfenced financial-authority window.
        $this->createFailClosedQueueFenceGuards();

        if (! Schema::hasTable('provisioning_financial_invalidations')) {
            Schema::create('provisioning_financial_invalidations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('order_id')->unique('prov_fin_inv_order_unique');
                $table->foreignId('purchase_settlement_id');
                $table->foreignId('payment_intent_id');
                $table->foreignId('purchase_refund_id');
                $table->dateTime('created_at', 6);

                $table->foreign('order_id', 'prov_fin_inv_order_fk')
                    ->references('id')->on('orders')->restrictOnDelete();
                $table->foreign('purchase_settlement_id', 'prov_fin_inv_settlement_fk')
                    ->references('id')->on('purchase_settlements')->restrictOnDelete();
                $table->foreign('payment_intent_id', 'prov_fin_inv_intent_fk')
                    ->references('id')->on('payment_intents')->restrictOnDelete();
                $table->foreign('purchase_refund_id', 'prov_fin_inv_refund_fk')
                    ->references('id')->on('purchase_refunds')->restrictOnDelete();
                $table->index(['payment_intent_id', 'id'], 'prov_fin_inv_intent_idx');
            });
        }

        $this->createInvalidationTableGuards();
        $this->createOrderFinancialLockGuard();
        $this->createOrderRefundBackfillTrigger();
        $this->createRefundInvalidationTrigger();
        $this->backfillExistingRefundInvalidations();
        $this->createPaymentIntentInvalidationGuard();

        // Re-enable queue creation only after the complete invalidation chain is durable.
        $this->createActiveQueueFenceGuards();
    }

    public function down(): void
    {
        if (Schema::hasTable('service_subscriptions') && DB::table('service_subscriptions')->exists()) {
            throw new RuntimeException('Cannot roll back provisioning financial invalidation while Service Subscriptions exist.');
        }
        if (DB::table('orders')->where('state', 'provisioning_queued')->exists()) {
            throw new RuntimeException('Cannot roll back provisioning financial invalidation while queued Orders exist.');
        }

        $this->createFailClosedQueueFenceGuards();
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_provisioning_invalidation_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_provisioning_invalidation');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_invalidation_after_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_financial_lock_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_financial_invalidations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_financial_invalidations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_financial_invalidations_insert_guard');

        Schema::dropIfExists('provisioning_financial_invalidations');

        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_invalidation_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_financial_invalidation_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_subscriptions_financial_invalidation_guard');
    }

    private function createFailClosedQueueFenceGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS service_subscriptions_financial_invalidation_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER service_subscriptions_financial_invalidation_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning queue financial invalidation migration is incomplete.';
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_financial_invalidation_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER provisioning_operations_financial_invalidation_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning queue financial invalidation migration is incomplete.';
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_invalidation_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_provisioning_invalidation_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    IF OLD.state = 'paid'
       AND OLD.state_version = 1
       AND NEW.state = 'provisioning_queued'
       AND NEW.state_version = 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning queue financial invalidation migration is incomplete.';
    END IF;
END
SQL);
    }

    private function createInvalidationTableGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_financial_invalidations_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER provisioning_financial_invalidations_insert_guard
BEFORE INSERT ON provisioning_financial_invalidations
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_authority_count
    FROM orders order_row
    INNER JOIN purchase_refunds refund_row
        ON refund_row.id = NEW.purchase_refund_id
       AND refund_row.purchase_settlement_id = order_row.purchase_settlement_id
       AND refund_row.payment_intent_id = order_row.payment_intent_id
       AND refund_row.user_id = order_row.user_id
    WHERE order_row.id = NEW.order_id
      AND order_row.purchase_settlement_id = NEW.purchase_settlement_id
      AND order_row.payment_intent_id = NEW.payment_intent_id;

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation requires one matching authoritative purchase refund.';
    END IF;
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_financial_invalidations_update_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER provisioning_financial_invalidations_update_guard
BEFORE UPDATE ON provisioning_financial_invalidations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation is immutable.';
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_financial_invalidations_delete_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER provisioning_financial_invalidations_delete_guard
BEFORE DELETE ON provisioning_financial_invalidations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning financial invalidation is non-deletable.';
END
SQL);
    }

    private function createOrderFinancialLockGuard(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_financial_lock_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_provisioning_financial_lock_guard
BEFORE INSERT ON orders
FOR EACH ROW
BEGIN
    DECLARE locked_settlement_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE locked_intent_id BIGINT UNSIGNED DEFAULT NULL;

    IF NEW.source_type = 'purchase'
       AND NEW.purchase_settlement_id IS NOT NULL
       AND NEW.payment_intent_id IS NOT NULL THEN
        SELECT id INTO locked_settlement_id
        FROM purchase_settlements
        WHERE id = NEW.purchase_settlement_id
        LIMIT 1
        FOR UPDATE;

        IF locked_settlement_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase Order requires authoritative settlement before financial invalidation coordination.';
        END IF;

        SELECT id INTO locked_intent_id
        FROM payment_intents
        WHERE id = NEW.payment_intent_id
        LIMIT 1
        FOR UPDATE;

        IF locked_intent_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase Order requires authoritative payment intent before financial invalidation coordination.';
        END IF;
    END IF;
END
SQL);
    }

    private function createOrderRefundBackfillTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_invalidation_after_insert');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_provisioning_invalidation_after_insert
AFTER INSERT ON orders
FOR EACH ROW
BEGIN
    DECLARE refund_id BIGINT UNSIGNED DEFAULT NULL;

    IF NEW.source_type = 'purchase' AND NEW.payment_intent_id IS NOT NULL THEN
        SELECT latest_purchase_refund_id INTO refund_id
        FROM payment_intents
        WHERE id = NEW.payment_intent_id
          AND state IN ('refund_pending','partially_refunded','refunded')
        LIMIT 1;

        IF refund_id IS NOT NULL THEN
            INSERT IGNORE INTO provisioning_financial_invalidations (
                order_id, purchase_settlement_id, payment_intent_id, purchase_refund_id, created_at
            ) VALUES (
                NEW.id, NEW.purchase_settlement_id, NEW.payment_intent_id, refund_id, CURRENT_TIMESTAMP(6)
            );
        END IF;
    END IF;
END
SQL);
    }

    private function createRefundInvalidationTrigger(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_refunds_provisioning_invalidation');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_refunds_provisioning_invalidation
AFTER INSERT ON purchase_refunds
FOR EACH ROW
BEGIN
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE purchase_settlement_id = NEW.purchase_settlement_id
      AND payment_intent_id = NEW.payment_intent_id
    LIMIT 1
    FOR UPDATE;

    IF locked_order_id IS NOT NULL THEN
        INSERT IGNORE INTO provisioning_financial_invalidations (
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
INSERT IGNORE INTO provisioning_financial_invalidations (
    order_id, purchase_settlement_id, payment_intent_id, purchase_refund_id, created_at
)
SELECT
    order_row.id,
    order_row.purchase_settlement_id,
    order_row.payment_intent_id,
    intent_row.latest_purchase_refund_id,
    CURRENT_TIMESTAMP(6)
FROM orders order_row
INNER JOIN payment_intents intent_row ON intent_row.id = order_row.payment_intent_id
INNER JOIN purchase_refunds refund_row ON refund_row.id = intent_row.latest_purchase_refund_id
WHERE intent_row.state IN ('refund_pending','partially_refunded','refunded')
  AND refund_row.purchase_settlement_id = order_row.purchase_settlement_id
  AND refund_row.payment_intent_id = order_row.payment_intent_id
SQL);
    }

    private function createPaymentIntentInvalidationGuard(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_provisioning_invalidation_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_intents_provisioning_invalidation_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_order_count INT DEFAULT 0;
    DECLARE invalidated_order_count INT DEFAULT 0;

    IF NEW.purpose = 'purchase'
       AND OLD.state IN ('captured','partially_refunded')
       AND NEW.state = 'refund_pending' THEN
        SELECT COUNT(*) INTO purchase_order_count
        FROM orders
        WHERE payment_intent_id = NEW.id;

        SELECT COUNT(*) INTO invalidated_order_count
        FROM orders order_row
        INNER JOIN provisioning_financial_invalidations invalidation_row
            ON invalidation_row.order_id = order_row.id
           AND invalidation_row.purchase_settlement_id = order_row.purchase_settlement_id
           AND invalidation_row.payment_intent_id = order_row.payment_intent_id
        WHERE order_row.payment_intent_id = NEW.id;

        IF purchase_order_count <> invalidated_order_count THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund must invalidate provisioning authority before refund_pending transition.';
        END IF;
    END IF;
END
SQL);
    }

    private function createActiveQueueFenceGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS service_subscriptions_financial_invalidation_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER service_subscriptions_financial_invalidation_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_count INT DEFAULT 0;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1
    FOR UPDATE;

    IF locked_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Subscription requires a durable Order financial-authority fence.';
    END IF;

    SELECT COUNT(*) INTO invalidation_count
    FROM provisioning_financial_invalidations
    WHERE order_id = locked_order_id;

    IF invalidation_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create a Service Subscription.';
    END IF;
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_financial_invalidation_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER provisioning_operations_financial_invalidation_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    DECLARE locked_order_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE invalidation_count INT DEFAULT 0;

    SELECT id INTO locked_order_id
    FROM orders
    WHERE id = NEW.order_id
    LIMIT 1
    FOR UPDATE;

    IF locked_order_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial Provisioning Operation requires a durable Order financial-authority fence.';
    END IF;

    SELECT COUNT(*) INTO invalidation_count
    FROM provisioning_financial_invalidations
    WHERE order_id = locked_order_id;

    IF invalidation_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot create an initial Provisioning Operation.';
    END IF;
END
SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS orders_provisioning_invalidation_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_provisioning_invalidation_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE invalidation_count INT DEFAULT 0;

    IF OLD.state = 'paid'
       AND OLD.state_version = 1
       AND NEW.state = 'provisioning_queued'
       AND NEW.state_version = 2 THEN
        SELECT COUNT(*) INTO invalidation_count
        FROM provisioning_financial_invalidations
        WHERE order_id = OLD.id;

        IF invalidation_count <> 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financially invalidated Order cannot transition to provisioning_queued.';
        END IF;
    END IF;
END
SQL);
    }
};