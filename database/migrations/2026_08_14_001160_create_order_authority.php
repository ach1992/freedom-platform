<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('source_type', 32);
            $table->foreignId('purchase_settlement_id')->nullable()->unique()->constrained('purchase_settlements')->restrictOnDelete();
            $table->ulid('purchase_settlement_public_id')->nullable();
            $table->foreignId('payment_intent_id')->nullable()->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->ulid('payment_intent_public_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_quote_id')->nullable()->constrained('quotes')->restrictOnDelete();
            $table->ulid('source_quote_public_id')->nullable();
            $table->char('source_quote_configuration_hash', 64)->nullable();
            $table->string('state', 32);
            $table->unsignedBigInteger('state_version');
            $table->bigInteger('total_amount_irr');
            $table->bigInteger('settled_amount_irr')->nullable();
            $table->char('currency', 3);
            $table->dateTime('paid_at', 6)->nullable();
            $table->string('creation_correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['user_id', 'created_at'], 'orders_user_created_idx');
            $table->index(['source_quote_id', 'created_at'], 'orders_quote_created_idx');
            $table->index(['state', 'created_at'], 'orders_state_created_idx');
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('source_quote_id')->constrained('quotes')->restrictOnDelete();
            $table->ulid('source_quote_public_id');
            $table->string('account_type_snapshot', 32);
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->string('offering_code_snapshot', 64);
            $table->unsignedBigInteger('offering_version');
            $table->char('offering_configuration_hash', 64);
            $table->bigInteger('base_price_irr');
            $table->string('override_source', 16);
            $table->string('override_reference_code', 64)->nullable();
            $table->bigInteger('override_price_irr')->nullable();
            $table->bigInteger('effective_price_irr');
            $table->string('discount_reference_code', 64)->nullable();
            $table->bigInteger('discount_irr');
            $table->bigInteger('final_price_irr');
            $table->char('currency', 3);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['order_id', 'line_number'], 'order_items_order_line_unique');
            $table->unique(['order_id', 'source_quote_id'], 'order_items_order_quote_unique');
            $table->index(['source_quote_id', 'created_at'], 'order_items_quote_created_idx');
        });

        Schema::create('order_state_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->unsignedBigInteger('from_version')->nullable();
            $table->unsignedBigInteger('to_version');
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason_code', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['order_id', 'to_version'], 'order_state_history_version_unique');
            $table->index(['order_id', 'created_at'], 'order_state_history_order_created_idx');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_source_type_chk CHECK (`source_type` = 'purchase')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_state_chk CHECK (`state` IN ('draft','quoted','awaiting_payment','payment_pending_review','paid','provisioning_queued','provisioning','completed','needs_review','canceled','refund_pending','refunded','partially_refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_state_version_chk CHECK (`state_version` >= 1)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_chk CHECK (`total_amount_irr` >= 0 AND (`settled_amount_irr` IS NULL OR `settled_amount_irr` >= 0))');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_purchase_shape_chk CHECK (`source_type` <> 'purchase' OR (`purchase_settlement_id` IS NOT NULL AND `purchase_settlement_public_id` IS NOT NULL AND `payment_intent_id` IS NOT NULL AND `payment_intent_public_id` IS NOT NULL AND `source_quote_id` IS NOT NULL AND `source_quote_public_id` IS NOT NULL AND `source_quote_configuration_hash` IS NOT NULL AND `state` = 'paid' AND `state_version` = 1 AND `paid_at` IS NOT NULL AND `total_amount_irr` > 0 AND `settled_amount_irr` > 0))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_quote_hash_chk CHECK (`source_quote_configuration_hash` IS NULL OR `source_quote_configuration_hash` REGEXP '^[0-9a-f]{64}$')");

        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_account_type_chk CHECK (`account_type_snapshot` IN ('customer','agent'))");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_override_source_chk CHECK (`override_source` IN ('none','account','tier','agent'))");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_prices_chk CHECK (`base_price_irr` >= 0 AND `effective_price_irr` >= 0 AND `discount_irr` >= 0 AND `discount_irr` <= `effective_price_irr` AND `final_price_irr` = `effective_price_irr` - `discount_irr`)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_hashes_chk CHECK (`offering_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_snapshot_bounds_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE order_state_histories ADD CONSTRAINT order_state_history_actor_chk CHECK (`actor_type` IN ('system','customer','agent','administrator'))");
        DB::statement('ALTER TABLE order_state_histories ADD CONSTRAINT order_state_history_version_chk CHECK (`to_version` >= 1 AND (`from_version` IS NULL OR `from_version` >= 1))');

        $this->createHistoryGuards();
        $this->createOrderGuards();
        $this->createOrderItemGuards();
    }

    public function down(): void
    {
        if (DB::table('orders')->exists()) {
            throw new RuntimeException('Cannot roll back Order authority while Orders exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS order_items_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_items_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_items_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_initial_history');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_state_histories_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_state_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_state_histories_insert_guard');
        Schema::dropIfExists('order_state_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }

    private function createOrderGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_insert_guard
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
      AND intent_row.wallet_account_id IS NULL
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_update_guard
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order mutation is not enabled by the current lifecycle authority.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_delete_guard
BEFORE DELETE ON orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Orders are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER orders_initial_history
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

    private function createOrderItemGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_items_insert_guard
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_items_update_guard
BEFORE UPDATE ON order_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order Items are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_items_delete_guard
BEFORE DELETE ON order_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order Items are non-deletable.';
END
SQL);
    }

    private function createHistoryGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_state_histories_insert_guard
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_state_histories_update_guard
BEFORE UPDATE ON order_state_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order state history is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_state_histories_delete_guard
BEFORE DELETE ON order_state_histories
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order state history is non-deletable.';
END
SQL);
    }
};
