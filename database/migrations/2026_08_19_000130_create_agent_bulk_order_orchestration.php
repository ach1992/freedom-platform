<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement AGT-003 AGT-004 BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('agent_bulk_orders')) {
            Schema::create('agent_bulk_orders', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->string('batch_key', 128)->unique();
                $table->char('request_payload_hash', 64);
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->unsignedSmallInteger('item_count');
                $table->string('creation_correlation_id', 64);
                $table->dateTime('created_at', 6);
                $table->index(['user_id', 'created_at'], 'agent_bulk_orders_user_created_idx');
            });
        } else {
            $this->assertParentTableFoundation();
        }

        if (! Schema::hasTable('agent_bulk_order_items')) {
            Schema::create('agent_bulk_order_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->foreignId('agent_bulk_order_id')->constrained('agent_bulk_orders')->restrictOnDelete();
                $table->unsignedSmallInteger('line_number');
                $table->string('child_key', 128);
                $table->foreignId('purchase_settlement_id')->unique()->constrained('purchase_settlements')->restrictOnDelete();
                $table->ulid('purchase_settlement_public_id');
                $table->foreignId('source_quote_id')->constrained('quotes')->restrictOnDelete();
                $table->ulid('source_quote_public_id');
                $table->string('state', 16);
                $table->unsignedSmallInteger('attempt_count');
                $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
                $table->foreignId('order_item_id')->nullable()->unique()->constrained('order_items')->restrictOnDelete();
                $table->string('last_error_code', 64)->nullable();
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->unique(['agent_bulk_order_id', 'line_number'], 'agent_bulk_items_order_line_unique');
                $table->unique(['agent_bulk_order_id', 'child_key'], 'agent_bulk_items_order_child_unique');
                $table->index(['agent_bulk_order_id', 'state'], 'agent_bulk_items_order_state_idx');
            });
        } else {
            $this->assertItemTableFoundation();
        }

        $this->replaceConstraint('agent_bulk_orders', 'agent_bulk_orders_hash_chk', "CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$')");
        $this->replaceConstraint('agent_bulk_orders', 'agent_bulk_orders_count_chk', 'CHECK (`item_count` BETWEEN 1 AND 50)');
        $this->replaceConstraint('agent_bulk_order_items', 'agent_bulk_items_state_chk', "CHECK (`state` IN ('pending','failed','succeeded'))");
        $this->replaceConstraint('agent_bulk_order_items', 'agent_bulk_items_attempt_chk', 'CHECK (`attempt_count` <= 100)');
        $this->replaceConstraint('agent_bulk_order_items', 'agent_bulk_items_result_shape_chk', "CHECK ((`state` = 'succeeded' AND `order_id` IS NOT NULL AND `order_item_id` IS NOT NULL AND `last_error_code` IS NULL) OR (`state` IN ('pending','failed') AND `order_id` IS NULL AND `order_item_id` IS NULL AND (`state` = 'pending' OR `last_error_code` IS NOT NULL)))");

        $this->createGuards();
        $this->assertAuthorityReady();
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_bulk_orders') && ! Schema::hasTable('agent_bulk_order_items')) {
            return;
        }
        if (Schema::hasTable('agent_bulk_orders') && DB::table('agent_bulk_orders')->exists()) {
            throw new RuntimeException('Cannot roll back agent bulk Order orchestration while parent Orders exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS agent_bulk_items_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_bulk_items_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_bulk_items_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_bulk_orders_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_bulk_orders_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_bulk_orders_insert_guard');
        Schema::dropIfExists('agent_bulk_order_items');
        Schema::dropIfExists('agent_bulk_orders');
    }

    private function assertParentTableFoundation(): void
    {
        foreach ([
            ['id', 'bigint', false, null, true],
            ['public_id', 'char', false, 26, false],
            ['batch_key', 'varchar', false, 128, false],
            ['request_payload_hash', 'char', false, 64, false],
            ['user_id', 'bigint', false, null, true],
            ['item_count', 'smallint', false, null, true],
            ['creation_correlation_id', 'varchar', false, 64, false],
            ['created_at', 'datetime', false, null, false],
        ] as [$column, $type, $nullable, $length, $unsigned]) {
            $this->assertColumnShape('agent_bulk_orders', $column, $type, $nullable, $length, $unsigned);
        }
        foreach ([
            ['PRIMARY', ['id'], true],
            ['agent_bulk_orders_public_id_unique', ['public_id'], true],
            ['agent_bulk_orders_batch_key_unique', ['batch_key'], true],
            ['agent_bulk_orders_user_created_idx', ['user_id', 'created_at'], false],
        ] as [$index, $columns, $unique]) {
            $this->assertIndexShape('agent_bulk_orders', $index, $columns, $unique);
        }
        $this->assertForeignKeyShape('agent_bulk_orders', 'agent_bulk_orders_user_id_foreign', 'user_id', 'users', 'id');
    }

    private function assertItemTableFoundation(): void
    {
        foreach ([
            ['id', 'bigint', false, null, true],
            ['public_id', 'char', false, 26, false],
            ['agent_bulk_order_id', 'bigint', false, null, true],
            ['line_number', 'smallint', false, null, true],
            ['child_key', 'varchar', false, 128, false],
            ['purchase_settlement_id', 'bigint', false, null, true],
            ['purchase_settlement_public_id', 'char', false, 26, false],
            ['source_quote_id', 'bigint', false, null, true],
            ['source_quote_public_id', 'char', false, 26, false],
            ['state', 'varchar', false, 16, false],
            ['attempt_count', 'smallint', false, null, true],
            ['order_id', 'bigint', true, null, true],
            ['order_item_id', 'bigint', true, null, true],
            ['last_error_code', 'varchar', true, 64, false],
            ['created_at', 'datetime', false, null, false],
            ['updated_at', 'datetime', false, null, false],
        ] as [$column, $type, $nullable, $length, $unsigned]) {
            $this->assertColumnShape('agent_bulk_order_items', $column, $type, $nullable, $length, $unsigned);
        }
        foreach ([
            ['PRIMARY', ['id'], true],
            ['agent_bulk_order_items_public_id_unique', ['public_id'], true],
            ['agent_bulk_order_items_purchase_settlement_id_unique', ['purchase_settlement_id'], true],
            ['agent_bulk_order_items_order_id_unique', ['order_id'], true],
            ['agent_bulk_order_items_order_item_id_unique', ['order_item_id'], true],
            ['agent_bulk_items_order_line_unique', ['agent_bulk_order_id', 'line_number'], true],
            ['agent_bulk_items_order_child_unique', ['agent_bulk_order_id', 'child_key'], true],
            ['agent_bulk_items_order_state_idx', ['agent_bulk_order_id', 'state'], false],
        ] as [$index, $columns, $unique]) {
            $this->assertIndexShape('agent_bulk_order_items', $index, $columns, $unique);
        }
        foreach ([
            ['agent_bulk_order_items_agent_bulk_order_id_foreign', 'agent_bulk_order_id', 'agent_bulk_orders', 'id'],
            ['agent_bulk_order_items_purchase_settlement_id_foreign', 'purchase_settlement_id', 'purchase_settlements', 'id'],
            ['agent_bulk_order_items_source_quote_id_foreign', 'source_quote_id', 'quotes', 'id'],
            ['agent_bulk_order_items_order_id_foreign', 'order_id', 'orders', 'id'],
            ['agent_bulk_order_items_order_item_id_foreign', 'order_item_id', 'order_items', 'id'],
        ] as [$constraint, $column, $referencedTable, $referencedColumn]) {
            $this->assertForeignKeyShape('agent_bulk_order_items', $constraint, $column, $referencedTable, $referencedColumn);
        }
    }

    private function replaceConstraint(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
    }

    private function assertAuthorityReady(): void
    {
        $this->assertParentTableFoundation();
        $this->assertItemTableFoundation();
        foreach ([
            ['agent_bulk_orders', 'agent_bulk_orders_hash_chk'],
            ['agent_bulk_orders', 'agent_bulk_orders_count_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_state_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_attempt_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_result_shape_chk'],
        ] as [$table, $constraint]) {
            if (! $this->constraintExists($table, $constraint)) {
                throw new RuntimeException('Agent bulk Order schema constraint did not converge: '.$constraint);
            }
        }
        foreach ([
            ['agent_bulk_orders_insert_guard', 'bounded immutable request authority'],
            ['agent_bulk_orders_update_guard', 'Agent bulk parent Orders are immutable.'],
            ['agent_bulk_orders_delete_guard', 'Agent bulk parent Orders are non-deletable.'],
            ['agent_bulk_items_insert_guard', 'clean bounded pending item'],
            ['agent_bulk_items_update_guard', 'Agent bulk child mutation requires application authority.'],
            ['agent_bulk_items_delete_guard', 'Agent bulk child items are non-deletable.'],
        ] as [$trigger, $needle]) {
            if (! $this->triggerContains($trigger, $needle)) {
                throw new RuntimeException('Agent bulk Order trigger authority did not converge: '.$trigger);
            }
        }
    }

    private function assertColumnShape(string $table, string $column, string $dataType, bool $nullable, ?int $length, bool $unsigned): void
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS character_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        if ($row === null
            || strtolower((string) $row->data_type) !== $dataType
            || ((string) $row->is_nullable === 'YES') !== $nullable
            || ($length !== null && (int) $row->character_length !== $length)
            || ($unsigned && ! str_contains(strtolower((string) $row->column_type), 'unsigned'))) {
            throw new RuntimeException("Agent bulk Order table has incompatible column shape: {$table}.{$column}");
        }
    }

    /** @param list<string> $columns */
    private function assertIndexShape(string $table, string $index, array $columns, bool $unique): void
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, $index],
        );
        $actual = array_map(static fn (object $row): string => (string) $row->column_name, $rows);
        $isUnique = $rows !== [] && (int) $rows[0]->non_unique === 0;
        if ($actual !== $columns || $isUnique !== $unique) {
            throw new RuntimeException("Agent bulk Order table has incompatible index shape: {$table}.{$index}");
        }
    }

    private function assertForeignKeyShape(string $table, string $constraint, string $column, string $referencedTable, string $referencedColumn): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT k.COLUMN_NAME AS column_name,
       k.REFERENCED_TABLE_NAME AS referenced_table,
       k.REFERENCED_COLUMN_NAME AS referenced_column,
       r.DELETE_RULE AS delete_rule
FROM information_schema.KEY_COLUMN_USAGE k
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
   AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
   AND r.TABLE_NAME = k.TABLE_NAME
WHERE k.CONSTRAINT_SCHEMA = DATABASE()
  AND k.TABLE_NAME = ?
  AND k.CONSTRAINT_NAME = ?
SQL, [$table, $constraint]);
        if ($row === null
            || (string) $row->column_name !== $column
            || (string) $row->referenced_table !== $referencedTable
            || (string) $row->referenced_column !== $referencedColumn
            || (string) $row->delete_rule !== 'RESTRICT') {
            throw new RuntimeException("Agent bulk Order table has incompatible foreign-key shape: {$table}.{$constraint}");
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
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

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER agent_bulk_orders_insert_guard
BEFORE INSERT ON agent_bulk_orders
FOR EACH ROW
BEGIN
    DECLARE valid_agent_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_agent_count
    FROM users user_row
    INNER JOIN agent_profiles agent_row ON agent_row.user_id = user_row.id
    WHERE user_row.id = NEW.user_id
      AND user_row.account_type = 'agent'
      AND user_row.account_status = 'active'
      AND agent_row.status = 'active'
      AND agent_row.pricing_profile_code IS NOT NULL;

    IF valid_agent_count <> 1
       OR NEW.item_count < 1
       OR NEW.item_count > 50
       OR NEW.request_payload_hash NOT REGEXP '^[0-9a-f]{64}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk Order requires one active agent and bounded immutable request authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER agent_bulk_orders_update_guard
BEFORE UPDATE ON agent_bulk_orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk parent Orders are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER agent_bulk_orders_delete_guard
BEFORE DELETE ON agent_bulk_orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk parent Orders are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER agent_bulk_items_insert_guard
BEFORE INSERT ON agent_bulk_order_items
FOR EACH ROW
BEGIN
    DECLARE valid_authority_count INT DEFAULT 0;

    IF NEW.state <> 'pending'
       OR NEW.attempt_count <> 0
       OR NEW.order_id IS NOT NULL
       OR NEW.order_item_id IS NOT NULL
       OR NEW.last_error_code IS NOT NULL
       OR NEW.line_number < 1
       OR NEW.line_number > 50 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk child must begin as a clean bounded pending item.';
    END IF;

    SELECT COUNT(*) INTO valid_authority_count
    FROM agent_bulk_orders bulk_row
    INNER JOIN purchase_settlements settlement_row ON settlement_row.id = NEW.purchase_settlement_id
    INNER JOIN payment_intents intent_row ON intent_row.id = settlement_row.payment_intent_id
    INNER JOIN quotes quote_row ON quote_row.id = settlement_row.source_quote_id
    INNER JOIN users user_row ON user_row.id = bulk_row.user_id
    INNER JOIN agent_profiles agent_row ON agent_row.user_id = bulk_row.user_id
    WHERE bulk_row.id = NEW.agent_bulk_order_id
      AND settlement_row.public_id = NEW.purchase_settlement_public_id
      AND settlement_row.user_id = bulk_row.user_id
      AND settlement_row.source_quote_id = NEW.source_quote_id
      AND settlement_row.source_quote_public_id = NEW.source_quote_public_id
      AND intent_row.id = settlement_row.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.user_id = bulk_row.user_id
      AND intent_row.source_quote_id = NEW.source_quote_id
      AND intent_row.source_quote_public_id = NEW.source_quote_public_id
      AND intent_row.state IN ('captured','refund_pending','refunded','partially_refunded')
      AND intent_row.captured_at IS NOT NULL
      AND quote_row.id = NEW.source_quote_id
      AND quote_row.public_id = NEW.source_quote_public_id
      AND quote_row.user_id = bulk_row.user_id
      AND quote_row.account_type_snapshot = 'agent'
      AND user_row.account_type = 'agent'
      AND user_row.account_status = 'active'
      AND agent_row.status = 'active';

    IF valid_authority_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk child requires one exact accepted agent purchase settlement authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER agent_bulk_items_update_guard
BEFORE UPDATE ON agent_bulk_order_items
FOR EACH ROW
BEGIN
    DECLARE valid_success_count INT DEFAULT 0;

    IF COALESCE(@app_agent_bulk_order_authority, '') <> 'agent_bulk_child_v1'
       OR COALESCE(@app_agent_bulk_order_public_id, '') = ''
       OR COALESCE(@app_agent_bulk_order_correlation_id, '') = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk child mutation requires application authority.';
    END IF;

    IF NOT (OLD.public_id <=> NEW.public_id)
       OR NOT (OLD.agent_bulk_order_id <=> NEW.agent_bulk_order_id)
       OR NOT (OLD.line_number <=> NEW.line_number)
       OR NOT (OLD.child_key <=> NEW.child_key)
       OR NOT (OLD.purchase_settlement_id <=> NEW.purchase_settlement_id)
       OR NOT (OLD.purchase_settlement_public_id <=> NEW.purchase_settlement_public_id)
       OR NOT (OLD.source_quote_id <=> NEW.source_quote_id)
       OR NOT (OLD.source_quote_public_id <=> NEW.source_quote_public_id)
       OR NOT (OLD.created_at <=> NEW.created_at)
       OR OLD.state = 'succeeded'
       OR NEW.attempt_count <> OLD.attempt_count + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk child immutable identity or attempt lifecycle is invalid.';
    END IF;

    IF NEW.state = 'succeeded' THEN
        IF NEW.order_id IS NULL OR NEW.order_item_id IS NULL OR NEW.last_error_code IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Successful agent bulk child requires exact Order result identity.';
        END IF;

        SELECT COUNT(*) INTO valid_success_count
        FROM agent_bulk_orders bulk_row
        INNER JOIN orders order_row ON order_row.id = NEW.order_id
        INNER JOIN order_items item_row ON item_row.id = NEW.order_item_id
        WHERE bulk_row.id = NEW.agent_bulk_order_id
          AND bulk_row.public_id = @app_agent_bulk_order_public_id
          AND order_row.user_id = bulk_row.user_id
          AND order_row.source_type = 'purchase'
          AND order_row.purchase_settlement_id = NEW.purchase_settlement_id
          AND order_row.purchase_settlement_public_id = NEW.purchase_settlement_public_id
          AND order_row.source_quote_id = NEW.source_quote_id
          AND order_row.source_quote_public_id = NEW.source_quote_public_id
          AND order_row.state IN ('paid','provisioning_queued','provisioning','completed','needs_review','refund_pending','refunded','partially_refunded')
          AND item_row.order_id = order_row.id
          AND item_row.line_number = 1
          AND item_row.source_quote_id = NEW.source_quote_id
          AND item_row.source_quote_public_id = NEW.source_quote_public_id;

        IF valid_success_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk child success must bind the exact canonical purchase Order and Item.';
        END IF;
    ELSEIF NEW.state = 'failed' THEN
        IF NEW.order_id IS NOT NULL
           OR NEW.order_item_id IS NOT NULL
           OR NEW.last_error_code IS NULL
           OR NEW.last_error_code NOT REGEXP '^[a-z0-9_.:-]{1,64}$' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Failed agent bulk child requires one safe failure code and no forged Order result.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk child may only finalize one execution attempt as succeeded or failed.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER agent_bulk_items_delete_guard
BEFORE DELETE ON agent_bulk_order_items
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent bulk child items are non-deletable.';
END
SQL);
    }
};
