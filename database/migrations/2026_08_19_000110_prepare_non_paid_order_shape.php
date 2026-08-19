<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 CAT-006 ADM-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        if (! $this->triggerContains('orders_unimplemented_source_insert_guard', "IF NEW.source_type <> 'purchase' THEN")) {
            throw new RuntimeException('Non-paid Order shape preparation requires the independent purchase-only source fence.');
        }

        $this->ensureSourceAuthorityColumns(
            'orders',
            'orders_source_authorization_unique',
            'orders_source_authorization_public_unique',
            'orders_order_source_authorization_id_foreign',
        );
        $this->ensureSourceAuthorityColumns(
            'order_items',
            'order_items_source_authorization_unique',
            'order_items_source_authorization_public_unique',
            'order_items_order_source_authorization_id_foreign',
        );
        $this->ensureQuoteColumnsNullable();

        $this->replaceConstraint(
            'orders',
            'orders_state_chk',
            "CHECK (`state` IN ('draft','quoted','awaiting_payment','payment_pending_review','authorized','paid','provisioning_queued','provisioning','completed','needs_review','canceled','refund_pending','refunded','partially_refunded'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_non_purchase_finance_shape_chk',
            <<<'SQL'
CHECK (
    `source_type` = 'purchase'
    OR (
        `purchase_settlement_id` IS NULL
        AND `purchase_settlement_public_id` IS NULL
        AND `payment_intent_id` IS NULL
        AND `payment_intent_public_id` IS NULL
        AND `source_quote_id` IS NULL
        AND `source_quote_public_id` IS NULL
        AND `source_quote_configuration_hash` IS NULL
        AND `settled_amount_irr` IS NULL
        AND `paid_at` IS NULL
        AND `total_amount_irr` = 0
    )
)
SQL,
        );
        $this->replaceConstraint(
            'order_items',
            'order_items_override_source_chk',
            "CHECK (`override_source` IN ('none','account','tier','agent','source'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_source_authorization_shape_chk',
            <<<'SQL'
CHECK (
    (`source_type` = 'purchase'
        AND `order_source_authorization_id` IS NULL
        AND `order_source_authorization_public_id` IS NULL)
    OR
    (`source_type` <> 'purchase'
        AND `order_source_authorization_id` IS NOT NULL
        AND `order_source_authorization_public_id` IS NOT NULL)
)
SQL,
        );
        $this->replaceConstraint(
            'orders',
            'orders_non_paid_lifecycle_chk',
            <<<'SQL'
CHECK (
    `source_type` = 'purchase'
    OR (`state` IN ('authorized','provisioning_queued','provisioning','completed','needs_review','canceled') AND `state_version` >= 0)
)
SQL,
        );
        $this->replaceConstraint(
            'order_items',
            'order_items_source_authority_shape_chk',
            <<<'SQL'
CHECK (
    (`source_quote_id` IS NOT NULL
        AND `source_quote_public_id` IS NOT NULL
        AND `order_source_authorization_id` IS NULL
        AND `order_source_authorization_public_id` IS NULL)
    OR
    (`source_quote_id` IS NULL
        AND `source_quote_public_id` IS NULL
        AND `order_source_authorization_id` IS NOT NULL
        AND `order_source_authorization_public_id` IS NOT NULL
        AND `override_source` = 'source'
        AND `override_reference_code` IS NOT NULL
        AND `override_price_irr` = 0
        AND `effective_price_irr` = 0
        AND `discount_irr` = 0
        AND `final_price_irr` = 0)
)
SQL,
        );

        $this->assertShapeReady();
    }

    public function down(): void
    {
        if ((Schema::hasColumn('orders', 'order_source_authorization_id')
                && DB::table('orders')->whereNotNull('order_source_authorization_id')->exists())
            || (Schema::hasColumn('order_items', 'order_source_authorization_id')
                && DB::table('order_items')->whereNotNull('order_source_authorization_id')->exists())) {
            throw new RuntimeException('Cannot roll back non-paid Order shape after source-authorized Orders exist.');
        }

        foreach ([
            ['order_items', 'order_items_source_authority_shape_chk'],
            ['orders', 'orders_non_paid_lifecycle_chk'],
            ['orders', 'orders_source_authorization_shape_chk'],
        ] as [$table, $constraint]) {
            $this->dropConstraintIfExists($table, $constraint);
        }
        $this->replaceConstraint(
            'order_items',
            'order_items_override_source_chk',
            "CHECK (`override_source` IN ('none','account','tier','agent'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_non_purchase_finance_shape_chk',
            "CHECK (`source_type` = 'purchase' OR (`purchase_settlement_id` IS NULL AND `purchase_settlement_public_id` IS NULL AND `payment_intent_id` IS NULL AND `payment_intent_public_id` IS NULL AND `settled_amount_irr` IS NULL))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_state_chk',
            "CHECK (`state` IN ('draft','quoted','awaiting_payment','payment_pending_review','paid','provisioning_queued','provisioning','completed','needs_review','canceled','refund_pending','refunded','partially_refunded'))",
        );

        $this->ensureQuoteColumnsNotNullable();
        $this->dropSourceAuthorityColumns('order_items', 'order_items_source_authorization_unique', 'order_items_source_authorization_public_unique', 'order_items_order_source_authorization_id_foreign');
        $this->dropSourceAuthorityColumns('orders', 'orders_source_authorization_unique', 'orders_source_authorization_public_unique', 'orders_order_source_authorization_id_foreign');
    }

    private function ensureSourceAuthorityColumns(string $table, string $idUnique, string $publicUnique, string $foreignKey): void
    {
        if (! Schema::hasColumn($table, 'order_source_authorization_id')) {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `order_source_authorization_id` BIGINT UNSIGNED NULL");
        } else {
            $this->assertColumnShape($table, 'order_source_authorization_id', 'bigint', true, null, true);
        }
        if (! Schema::hasColumn($table, 'order_source_authorization_public_id')) {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `order_source_authorization_public_id` CHAR(26) NULL");
        } else {
            $this->assertColumnShape($table, 'order_source_authorization_public_id', 'char', true, 26, false);
        }

        $this->ensureUniqueIndex($table, $idUnique, ['order_source_authorization_id']);
        $this->ensureUniqueIndex($table, $publicUnique, ['order_source_authorization_public_id']);
        $this->ensureForeignKey($table, $foreignKey, 'order_source_authorization_id', 'order_source_authorizations', 'id');
    }

    private function ensureQuoteColumnsNullable(): void
    {
        $sourceQuoteId = $this->columnMetadata('order_items', 'source_quote_id');
        $this->assertColumnMetadata('order_items', 'source_quote_id', $sourceQuoteId, 'bigint', null, true);
        if ((string) $sourceQuoteId->is_nullable !== 'YES') {
            DB::statement('ALTER TABLE order_items MODIFY `source_quote_id` BIGINT UNSIGNED NULL');
        }

        $sourceQuotePublicId = $this->columnMetadata('order_items', 'source_quote_public_id');
        $this->assertColumnMetadata('order_items', 'source_quote_public_id', $sourceQuotePublicId, 'char', 26, false);
        if ((string) $sourceQuotePublicId->is_nullable !== 'YES') {
            DB::statement('ALTER TABLE order_items MODIFY `source_quote_public_id` CHAR(26) NULL');
        }
    }

    private function ensureQuoteColumnsNotNullable(): void
    {
        $sourceQuoteId = $this->columnMetadata('order_items', 'source_quote_id');
        $this->assertColumnMetadata('order_items', 'source_quote_id', $sourceQuoteId, 'bigint', null, true);
        if ((string) $sourceQuoteId->is_nullable === 'YES') {
            if (DB::table('order_items')->whereNull('source_quote_id')->exists()) {
                throw new RuntimeException('Cannot restore purchase-only Order Item shape while source Quote IDs are absent.');
            }
            DB::statement('ALTER TABLE order_items MODIFY `source_quote_id` BIGINT UNSIGNED NOT NULL');
        }

        $sourceQuotePublicId = $this->columnMetadata('order_items', 'source_quote_public_id');
        $this->assertColumnMetadata('order_items', 'source_quote_public_id', $sourceQuotePublicId, 'char', 26, false);
        if ((string) $sourceQuotePublicId->is_nullable === 'YES') {
            if (DB::table('order_items')->whereNull('source_quote_public_id')->exists()) {
                throw new RuntimeException('Cannot restore purchase-only Order Item shape while source Quote public IDs are absent.');
            }
            DB::statement('ALTER TABLE order_items MODIFY `source_quote_public_id` CHAR(26) NOT NULL');
        }
    }

    private function dropSourceAuthorityColumns(string $table, string $idUnique, string $publicUnique, string $foreignKey): void
    {
        $this->dropForeignKeyIfExists($table, $foreignKey);
        $this->dropIndexIfExists($table, $idUnique);
        $this->dropIndexIfExists($table, $publicUnique);
        if (Schema::hasColumn($table, 'order_source_authorization_public_id')) {
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `order_source_authorization_public_id`");
        }
        if (Schema::hasColumn($table, 'order_source_authorization_id')) {
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `order_source_authorization_id`");
        }
    }

    private function replaceConstraint(string $table, string $name, string $definition): void
    {
        $this->dropConstraintIfExists($table, $name);
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` {$definition}");
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }
    }

    /** @param list<string> $columns */
    private function ensureUniqueIndex(string $table, string $index, array $columns): void
    {
        $rows = $this->indexRows($table, $index);
        if ($rows === []) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD UNIQUE INDEX `%s` (%s)',
                $table,
                $index,
                implode(', ', array_map(static fn (string $column): string => '`'.$column.'`', $columns)),
            ));

            return;
        }
        $this->assertIndexRows($table, $index, $rows, $columns, true);
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if ($this->indexRows($table, $index) !== []) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function ensureForeignKey(string $table, string $constraint, string $column, string $referencedTable, string $referencedColumn): void
    {
        $row = $this->foreignKeyMetadata($table, $constraint);
        if ($row === null) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}` (`{$referencedColumn}`) ON DELETE RESTRICT");

            return;
        }
        $this->assertForeignKeyMetadata($table, $constraint, $row, $column, $referencedTable, $referencedColumn);
    }

    private function dropForeignKeyIfExists(string $table, string $constraint): void
    {
        if ($this->foreignKeyMetadata($table, $constraint) !== null) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }

    private function assertShapeReady(): void
    {
        foreach ([
            ['orders', 'orders_source_authorization_unique', 'orders_source_authorization_public_unique', 'orders_order_source_authorization_id_foreign'],
            ['order_items', 'order_items_source_authorization_unique', 'order_items_source_authorization_public_unique', 'order_items_order_source_authorization_id_foreign'],
        ] as [$table, $idUnique, $publicUnique, $foreignKey]) {
            $this->assertColumnShape($table, 'order_source_authorization_id', 'bigint', true, null, true);
            $this->assertColumnShape($table, 'order_source_authorization_public_id', 'char', true, 26, false);
            $this->assertIndexRows($table, $idUnique, $this->indexRows($table, $idUnique), ['order_source_authorization_id'], true);
            $this->assertIndexRows($table, $publicUnique, $this->indexRows($table, $publicUnique), ['order_source_authorization_public_id'], true);
            $row = $this->foreignKeyMetadata($table, $foreignKey);
            if ($row === null) {
                throw new RuntimeException('Non-paid Order shape foreign key did not converge: '.$foreignKey);
            }
            $this->assertForeignKeyMetadata($table, $foreignKey, $row, 'order_source_authorization_id', 'order_source_authorizations', 'id');
        }
        $this->assertColumnShape('order_items', 'source_quote_id', 'bigint', true, null, true);
        $this->assertColumnShape('order_items', 'source_quote_public_id', 'char', true, 26, false);
        foreach ([
            ['orders', 'orders_state_chk'],
            ['orders', 'orders_non_purchase_finance_shape_chk'],
            ['orders', 'orders_source_authorization_shape_chk'],
            ['orders', 'orders_non_paid_lifecycle_chk'],
            ['order_items', 'order_items_override_source_chk'],
            ['order_items', 'order_items_source_authority_shape_chk'],
        ] as [$table, $constraint]) {
            if (! $this->constraintExists($table, $constraint)) {
                throw new RuntimeException('Non-paid Order shape constraint did not converge: '.$constraint);
            }
        }
        if (! $this->triggerContains('orders_unimplemented_source_insert_guard', "IF NEW.source_type <> 'purchase' THEN")) {
            throw new RuntimeException('Non-paid Order shape preparation lost the purchase-only source fence.');
        }
    }

    private function assertColumnShape(string $table, string $column, string $dataType, bool $nullable, ?int $length, bool $unsigned): void
    {
        $row = $this->columnMetadata($table, $column);
        $this->assertColumnMetadata($table, $column, $row, $dataType, $length, $unsigned);
        if (((string) $row->is_nullable === 'YES') !== $nullable) {
            throw new RuntimeException("Non-paid Order shape has incompatible column nullability: {$table}.{$column}");
        }
    }

    /** @return object{data_type:string,column_type:string,is_nullable:string,character_length:int|string|null} */
    private function columnMetadata(string $table, string $column): object
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS character_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        if ($row === null) {
            throw new RuntimeException("Non-paid Order shape column is missing: {$table}.{$column}");
        }

        return $row;
    }

    /** @param object{data_type:string,column_type:string,is_nullable:string,character_length:int|string|null} $row */
    private function assertColumnMetadata(string $table, string $column, object $row, string $dataType, ?int $length, bool $unsigned): void
    {
        if (strtolower((string) $row->data_type) !== $dataType
            || ($length !== null && (int) $row->character_length !== $length)
            || ($unsigned && ! str_contains(strtolower((string) $row->column_type), 'unsigned'))) {
            throw new RuntimeException("Non-paid Order shape has incompatible column type: {$table}.{$column}");
        }
    }

    /** @return list<object{column_name:string,non_unique:int|string}> */
    private function indexRows(string $table, string $index): array
    {
        /** @var list<object{column_name:string,non_unique:int|string}> $rows */
        $rows = DB::select(
            'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, $index],
        );

        return $rows;
    }

    /**
     * @param list<object{column_name:string,non_unique:int|string}> $rows
     * @param list<string> $columns
     */
    private function assertIndexRows(string $table, string $index, array $rows, array $columns, bool $unique): void
    {
        $actual = array_map(static fn ($row): string => (string) $row->column_name, $rows);
        $isUnique = $rows !== [] && (int) $rows[0]->non_unique === 0;
        if ($actual !== $columns || $isUnique !== $unique) {
            throw new RuntimeException("Non-paid Order shape has incompatible index: {$table}.{$index}");
        }
    }

    /** @return object{column_name:string,referenced_table:string,referenced_column:string,delete_rule:string}|null */
    private function foreignKeyMetadata(string $table, string $constraint): ?object
    {
        return DB::selectOne(<<<'SQL'
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
    }

    /** @param object{column_name:string,referenced_table:string,referenced_column:string,delete_rule:string} $row */
    private function assertForeignKeyMetadata(string $table, string $constraint, object $row, string $column, string $referencedTable, string $referencedColumn): void
    {
        if ((string) $row->column_name !== $column
            || (string) $row->referenced_table !== $referencedTable
            || (string) $row->referenced_column !== $referencedColumn
            || (string) $row->delete_rule !== 'RESTRICT') {
            throw new RuntimeException("Non-paid Order shape has incompatible foreign key: {$table}.{$constraint}");
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
};
