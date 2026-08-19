<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 AGT-003 AGT-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class NonPaidOrderMigrationReentryTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_authority_migration_converges_after_table_create_commits_without_migration_record(): void
    {
        /** @var Migration $source */
        $source = require database_path('migrations/2026_08_19_000100_create_non_paid_order_source_authority.php');
        /** @var Migration $shape */
        $shape = require database_path('migrations/2026_08_19_000110_prepare_non_paid_order_shape.php');
        /** @var Migration $invalidation */
        $invalidation = require database_path('migrations/2026_08_19_000115_extend_provisioning_invalidation_to_non_paid_sources.php');
        /** @var Migration $activation */
        $activation = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
        $injected = false;

        try {
            $activation->down();
            $invalidation->down();
            $shape->down();
            $source->down();
            $this->assertPurchaseOnlySourceFence();

            DB::listen(function (QueryExecuted $query) use (&$injected): void {
                if ($injected || ! str_contains(strtolower($query->sql), 'create table `order_source_authorizations`')) {
                    return;
                }

                $injected = true;
                throw new RuntimeException('Injected after committed Order source authorization table creation.');
            });

            try {
                $source->up();
                self::fail('The fault injector must interrupt after the source-authority table CREATE commits.');
            } catch (RuntimeException $exception) {
                self::assertTrue($injected);
                self::assertSame('Injected after committed Order source authorization table creation.', $exception->getMessage());
            }

            self::assertTrue(Schema::hasTable('order_source_authorizations'));
            self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_auth_type_chk'));
            self::assertFalse($this->triggerExists('order_source_authorizations_insert_guard'));
            $this->assertPurchaseOnlySourceFence();

            $source->up();
            $this->assertSourceAuthorityReady();
            $this->assertPurchaseOnlySourceFence();
        } finally {
            $injected = true;
            $source->up();
            $shape->up();
            $invalidation->up();
            $activation->up();
        }

        $this->assertSupportedSourceFence();
    }

    public function test_order_shape_migration_converges_after_one_table_alter_and_drop_before_add_cut(): void
    {
        /** @var Migration $shape */
        $shape = require database_path('migrations/2026_08_19_000110_prepare_non_paid_order_shape.php');
        /** @var Migration $invalidation */
        $invalidation = require database_path('migrations/2026_08_19_000115_extend_provisioning_invalidation_to_non_paid_sources.php');
        /** @var Migration $activation */
        $activation = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
        $injected = false;

        try {
            $activation->down();
            $invalidation->down();
            $shape->down();
            $this->assertPurchaseOnlySourceFence();

            // Model a committed first table alteration with the second table still untouched.
            DB::statement('ALTER TABLE orders ADD COLUMN `order_source_authorization_id` BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE orders ADD COLUMN `order_source_authorization_public_id` CHAR(26) NULL');
            DB::statement('ALTER TABLE orders ADD UNIQUE INDEX `orders_source_authorization_unique` (`order_source_authorization_id`)');
            DB::statement('ALTER TABLE orders ADD UNIQUE INDEX `orders_source_authorization_public_unique` (`order_source_authorization_public_id`)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT `orders_order_source_authorization_id_foreign` FOREIGN KEY (`order_source_authorization_id`) REFERENCES `order_source_authorizations` (`id`) ON DELETE RESTRICT');

            self::assertTrue(Schema::hasColumn('orders', 'order_source_authorization_id'));
            self::assertFalse(Schema::hasColumn('order_items', 'order_source_authorization_id'));

            $shape->up();
            $this->assertPreparedOrderShapeReady();
            $this->assertPurchaseOnlySourceFence();

            DB::listen(function (QueryExecuted $query) use (&$injected): void {
                if ($injected || ! str_contains(strtolower($query->sql), 'drop constraint `orders_state_chk`')) {
                    return;
                }

                $injected = true;
                throw new RuntimeException('Injected after committed non-paid Order state constraint drop.');
            });

            try {
                $shape->up();
                self::fail('The fault injector must interrupt after the constraint DROP commits.');
            } catch (RuntimeException $exception) {
                self::assertTrue($injected);
                self::assertSame('Injected after committed non-paid Order state constraint drop.', $exception->getMessage());
            }

            self::assertFalse($this->constraintExists('orders', 'orders_state_chk'));
            $this->assertPurchaseOnlySourceFence();

            $shape->up();
            $this->assertPreparedOrderShapeReady();
            $this->assertPurchaseOnlySourceFence();
        } finally {
            $injected = true;
            $shape->up();
            $invalidation->up();
            $activation->up();
        }

        $this->assertSupportedSourceFence();
    }

    public function test_agent_bulk_migration_converges_after_parent_table_create_commits_without_migration_record(): void
    {
        /** @var Migration $bulk */
        $bulk = require database_path('migrations/2026_08_19_000130_create_agent_bulk_order_orchestration.php');
        $injected = false;

        try {
            $bulk->down();
            $this->assertSupportedSourceFence();

            DB::listen(function (QueryExecuted $query) use (&$injected): void {
                if ($injected || ! str_contains(strtolower($query->sql), 'create table `agent_bulk_orders`')) {
                    return;
                }

                $injected = true;
                throw new RuntimeException('Injected after committed Agent bulk parent table creation.');
            });

            try {
                $bulk->up();
                self::fail('The fault injector must interrupt after the Agent bulk parent CREATE commits.');
            } catch (RuntimeException $exception) {
                self::assertTrue($injected);
                self::assertSame('Injected after committed Agent bulk parent table creation.', $exception->getMessage());
            }

            self::assertTrue(Schema::hasTable('agent_bulk_orders'));
            self::assertFalse(Schema::hasTable('agent_bulk_order_items'));
            self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_count_chk'));
            $this->assertSupportedSourceFence();

            $bulk->up();
            $this->assertAgentBulkAuthorityReady();
            $this->assertSupportedSourceFence();
        } finally {
            $injected = true;
            $bulk->up();
        }
    }

    private function assertSourceAuthorityReady(): void
    {
        foreach ([
            'order_source_auth_type_chk',
            'order_source_auth_actor_chk',
            'order_source_auth_upstream_shape_chk',
            'order_source_auth_hash_chk',
            'order_source_auth_snapshot_chk',
            'order_source_auth_reason_chk',
        ] as $constraint) {
            self::assertTrue($this->constraintExists('order_source_authorizations', $constraint), $constraint.' must exist.');
        }
        self::assertTrue($this->triggerContains('order_source_authorizations_insert_guard', 'Unsupported non-paid Order authorization source.'));
        self::assertTrue($this->triggerContains('order_source_authorizations_update_guard', 'Order source authorizations are immutable.'));
        self::assertTrue($this->triggerContains('order_source_authorizations_delete_guard', 'Order source authorizations are non-deletable.'));
    }

    private function assertPreparedOrderShapeReady(): void
    {
        foreach (['orders', 'order_items'] as $table) {
            self::assertTrue(Schema::hasColumn($table, 'order_source_authorization_id'));
            self::assertTrue(Schema::hasColumn($table, 'order_source_authorization_public_id'));
        }
        self::assertTrue($this->indexMatches('orders', 'orders_source_authorization_unique', ['order_source_authorization_id'], true));
        self::assertTrue($this->indexMatches('orders', 'orders_source_authorization_public_unique', ['order_source_authorization_public_id'], true));
        self::assertTrue($this->indexMatches('order_items', 'order_items_source_authorization_unique', ['order_source_authorization_id'], true));
        self::assertTrue($this->indexMatches('order_items', 'order_items_source_authorization_public_unique', ['order_source_authorization_public_id'], true));
        self::assertTrue($this->foreignKeyMatches('orders', 'orders_order_source_authorization_id_foreign', 'order_source_authorization_id', 'order_source_authorizations', 'id'));
        self::assertTrue($this->foreignKeyMatches('order_items', 'order_items_order_source_authorization_id_foreign', 'order_source_authorization_id', 'order_source_authorizations', 'id'));
        foreach ([
            ['orders', 'orders_state_chk'],
            ['orders', 'orders_non_purchase_finance_shape_chk'],
            ['orders', 'orders_source_authorization_shape_chk'],
            ['orders', 'orders_non_paid_lifecycle_chk'],
            ['order_items', 'order_items_override_source_chk'],
            ['order_items', 'order_items_source_authority_shape_chk'],
        ] as [$table, $constraint]) {
            self::assertTrue($this->constraintExists($table, $constraint), $constraint.' must exist.');
        }
        self::assertSame('YES', $this->columnNullability('order_items', 'source_quote_id'));
        self::assertSame('YES', $this->columnNullability('order_items', 'source_quote_public_id'));
    }

    private function assertAgentBulkAuthorityReady(): void
    {
        self::assertTrue(Schema::hasTable('agent_bulk_orders'));
        self::assertTrue(Schema::hasTable('agent_bulk_order_items'));
        foreach ([
            ['agent_bulk_orders', 'agent_bulk_orders_hash_chk'],
            ['agent_bulk_orders', 'agent_bulk_orders_count_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_state_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_attempt_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_result_shape_chk'],
        ] as [$table, $constraint]) {
            self::assertTrue($this->constraintExists($table, $constraint), $constraint.' must exist.');
        }
        self::assertTrue($this->triggerContains('agent_bulk_orders_insert_guard', 'bounded immutable request authority'));
        self::assertTrue($this->triggerContains('agent_bulk_items_insert_guard', 'accepted agent purchase settlement authority'));
        self::assertTrue($this->triggerContains('agent_bulk_items_update_guard', 'exact canonical purchase Order and Item'));
    }

    private function assertPurchaseOnlySourceFence(): void
    {
        self::assertTrue($this->triggerContains('orders_unimplemented_source_insert_guard', "IF NEW.source_type <> 'purchase' THEN"));
        foreach (['trial', 'benefit_code', 'admin_grant'] as $sourceType) {
            self::assertFalse($this->triggerContains('orders_unimplemented_source_insert_guard', "HEX('{$sourceType}')"));
        }
    }

    private function assertSupportedSourceFence(): void
    {
        foreach (['purchase', 'trial', 'benefit_code', 'admin_grant'] as $sourceType) {
            self::assertTrue($this->triggerContains('orders_unimplemented_source_insert_guard', "HEX('{$sourceType}')"));
        }
        foreach (['gift', 'service_code'] as $sourceType) {
            self::assertFalse($this->triggerContains('orders_unimplemented_source_insert_guard', "HEX('{$sourceType}')"));
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

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
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

    /** @param list<string> $columns */
    private function indexMatches(string $table, string $index, array $columns, bool $unique): bool
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, $index],
        );
        if ($rows === []) {
            return false;
        }
        $actual = array_map(static fn (object $row): string => (string) $row->column_name, $rows);

        return $actual === $columns && ((int) $rows[0]->non_unique === 0) === $unique;
    }

    private function foreignKeyMatches(string $table, string $constraint, string $column, string $referencedTable, string $referencedColumn): bool
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

        return $row !== null
            && (string) $row->column_name === $column
            && (string) $row->referenced_table === $referencedTable
            && (string) $row->referenced_column === $referencedColumn
            && (string) $row->delete_rule === 'RESTRICT';
    }

    private function columnNullability(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        self::assertNotNull($row);

        return (string) $row->is_nullable;
    }
}
