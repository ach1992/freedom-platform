<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\ArchitectureBoundaryChecker;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/ArchitectureBoundaryChecker.php';

final class ArchitecturePersistenceHardeningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-persistence-hardening-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_literal_foreach_table_set_is_statically_attributed_but_unbounded_dynamic_write_fails(): void
    {
        $this->write('app/Modules/Catalog/Application/Bounded.php', <<<'PHP'
<?php
namespace App\Modules\Catalog\Application;
final class Bounded
{
    public function run($db): void
    {
        foreach ([
            'custom_plan_policy_tiers',
            'custom_plan_policy_tags',
        ] as $table) {
            $db->table($table)->delete();
        }
    }
}
PHP);
        $this->write('app/Modules/Catalog/Application/Unbounded.php', <<<'PHP'
<?php
namespace App\Modules\Catalog\Application;
final class Unbounded { public function run($db, string $table): void { $db->table($table)->delete(); } }
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(1, substr_count($violations, 'dynamic table mutation is forbidden'));
        self::assertStringContainsString('Unbounded.php', $violations);
    }

    public function test_deferred_query_builder_mutation_cannot_detach_table_ownership(): void
    {
        $this->write('app/Modules/Orders/Application/Deferred.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class Deferred { public function run($db): void { $query = $db->table('orders')->where('id', 1); $query->update(['state' => 'paid']); } }
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('deferred table mutation', $violations);
    }

    public function test_application_raw_dml_and_dynamic_statement_fail_closed_but_literal_non_dml_statement_is_allowed(): void
    {
        $this->write('app/Modules/Orders/Application/RawSql.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Support\Facades\DB;
final class RawSql
{
    public function unsafe(string $sql): void
    {
        DB::statement('DELETE FROM orders');
        DB::statement($sql);
    }

    public function sessionOnly(): void
    {
        DB::statement('SET @freedom_test = 1');
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(1, substr_count($violations, 'opaque raw SQL mutation'));
        self::assertSame(1, substr_count($violations, 'non-literal/unsupported statement'));
    }

    public function test_nowdoc_session_statement_is_allowed_but_ddl_requires_an_exact_migration_helper(): void
    {
        $path = 'app/Modules/Orders/Infrastructure/OrderMigrationGuard.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Orders\Infrastructure;
use Illuminate\Support\Facades\DB;
final class OrderMigrationGuard
{
    public function session(): void
    {
        DB::statement(<<<'SQL'
SET @freedom_test = 1,
    @freedom_second = 2
SQL);
    }

    public function drop(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_guard');
    }
}
PHP);

        $blocked = implode("\n", $this->checker()->check()['violations']);
        self::assertSame(1, substr_count($blocked, 'opaque raw SQL mutation'));
        self::assertStringNotContainsString('non-literal/unsupported statement', $blocked);

        $this->write('database/migrations/2026_08_27_000000_order_migration_guard.php', <<<'PHP'
<?php
use App\Modules\Orders\Infrastructure\OrderMigrationGuard;
return new class { public function up(): void { OrderMigrationGuard::drop(); } };
PHP);

        $accepted = $this->checker([], [$path])->check()['violations'];
        self::assertSame([], $accepted);

        $stale = implode("\n", $this->checker([], [$path, 'app/Modules/Orders/Infrastructure/RemovedGuard.php'])->check()['violations']);
        self::assertStringContainsString('migration_trigger_ddl_helpers contains stale/unused helper', $stale);

        $this->write('app/Modules/Orders/Infrastructure/RuntimeCaller.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Infrastructure;
final class RuntimeCaller { public function run(): void { OrderMigrationGuard::drop(); } }
PHP);
        $runtimeReference = implode("\n", $this->checker([], [$path])->check()['violations']);
        self::assertStringContainsString('migration-only trigger DDL helper', $runtimeReference);
        self::assertStringContainsString('may not be referenced from runtime source', $runtimeReference);
    }

    public function test_safe_raw_prefixes_do_not_allow_a_second_statement(): void
    {
        $path = 'app/Modules/Orders/Infrastructure/PrefixBypass.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Orders\Infrastructure;
use Illuminate\Support\Facades\DB;
final class PrefixBypass
{
    public static function run(): void
    {
        DB::statement('SET @freedom_test = 1; DELETE FROM orders');
        DB::unprepared('DROP TRIGGER IF EXISTS order_guard; DROP TABLE orders');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_guard BEFORE UPDATE ON orders FOR EACH ROW
BEGIN
    SET @freedom_test = 1;
END;
DROP TABLE orders
SQL);
    }
}
PHP);
        $this->write('database/migrations/2026_08_27_000002_prefix_bypass.php', <<<'PHP'
<?php
use App\Modules\Orders\Infrastructure\PrefixBypass;
return new class { public function up(): void { PrefixBypass::run(); } };
PHP);

        $violations = implode("\n", $this->checker([], [$path])->check()['violations']);
        self::assertSame(3, substr_count($violations, 'opaque raw SQL'));
    }

    public function test_migration_trigger_helper_does_not_grant_general_raw_ddl(): void
    {
        $path = 'app/Modules/Orders/Infrastructure/GenericDdlGuard.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Orders\Infrastructure;
use Illuminate\Support\Facades\DB;
final class GenericDdlGuard
{
    public static function run(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_guard');
        DB::unprepared('CREATE TABLE should_not_pass (id BIGINT)');
    }
}
PHP);
        $this->write('database/migrations/2026_08_27_000001_generic_ddl_guard.php', <<<'PHP'
<?php
use App\Modules\Orders\Infrastructure\GenericDdlGuard;
return new class { public function up(): void { GenericDdlGuard::run(); } };
PHP);

        $violations = implode("\n", $this->checker([], [$path])->check()['violations']);
        self::assertSame(1, substr_count($violations, 'opaque raw SQL mutation'));
    }

    public function test_only_connection_scoped_user_variable_set_statements_are_allowed(): void
    {
        $this->write('app/Modules/Orders/Application/SetScope.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Support\Facades\DB;
final class SetScope
{
    public function run(): void
    {
        DB::statement('SET @freedom_local = 1');
        DB::statement('SET GLOBAL sql_mode = \'STRICT_ALL_TABLES\'');
        DB::statement('SET @@GLOBAL.sql_mode = \'STRICT_ALL_TABLES\'');
        DB::statement('SET sql_mode = \'STRICT_ALL_TABLES\'');
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(3, substr_count($violations, 'non-literal/unsupported statement'));
    }

    public function test_all_supported_query_builder_write_apis_are_attributed(): void
    {
        $expectedMethods = [
            'decrement',
            'decrementEach',
            'delete',
            'increment',
            'incrementEach',
            'insert',
            'insertGetId',
            'insertOrIgnore',
            'insertOrIgnoreReturning',
            'insertOrIgnoreUsing',
            'insertUsing',
            'truncate',
            'update',
            'updateFrom',
            'updateOrInsert',
            'upsert',
        ];
        $writeLikeMethods = array_values(array_filter(
            get_class_methods(Builder::class),
            static fn (string $method): bool => preg_match('/^(?:insert|update|delete|upsert|increment|decrement|truncate)/', $method) === 1,
        ));
        sort($writeLikeMethods, SORT_STRING);
        self::assertSame($expectedMethods, $writeLikeMethods, 'Laravel Query Builder write surface changed; update architecture attribution deliberately.');

        $template = <<<'PHP'
<?php
namespace App\Modules\Customers\Application;
final class __CLASS__
{
    public function run($db): void
    {
        $db->table('orders')->__METHOD__();
    }
}
PHP;
        foreach ($writeLikeMethods as $index => $method) {
            $class = 'WriteApiBypass'.$index;
            $this->write(
                'app/Modules/Customers/Application/'.$class.'.php',
                str_replace(['__CLASS__', '__METHOD__'], [$class, $method], $template),
            );
        }

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(count($writeLikeMethods), substr_count($violations, 'mutation of durable table orders owned by Orders is forbidden'));
    }

    public function test_query_builder_from_variants_cannot_bypass_table_attribution(): void
    {
        $this->write('app/Modules/Customers/Application/FromBypasses.php', <<<'PHP'
<?php
namespace App\Modules\Customers\Application;
final class FromBypasses
{
    public function run($db, string $table): void
    {
        $db->query()->from('orders')->update([]);
        $db->query()->from($table)->delete();
        $db->query()->fromRaw('orders')->update([]);
        $deferred = $db->query()->from('orders');
        $deferred->delete();
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('Customers mutation of durable table orders owned by Orders is forbidden', $violations);
        self::assertStringContainsString('dynamic table mutation is forbidden', $violations);
        self::assertStringContainsString('query mutation through fromRaw is forbidden', $violations);
        self::assertStringContainsString('deferred table mutation through $deferred is forbidden', $violations);
    }

    public function test_runtime_schema_introspection_is_allowed_but_mutation_surfaces_fail_closed(): void
    {
        $this->write('app/Modules/Orders/Application/RuntimeSchema.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Support\Facades\Schema;
final class RuntimeSchema
{
    public function run($connection): void
    {
        Schema::hasTable('orders');
        $connection->getSchemaBuilder()->hasTable('orders');
        Schema::dropIfExists('orders');
        $connection->getSchemaBuilder()->dropIfExists('orders');
        Schema::whenTableHasColumn('orders', 'id', static function (): void {});
        $connection->getSchemaBuilder()->getConnection();
        $schema = $connection->getSchemaBuilder();
        $schema->rename('orders', 'orders_archive');
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/AliasedRuntimeSchema.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
final class AliasedRuntimeSchema { public function run(): void { DatabaseSchema::dropIfExists('orders'); } }
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(4, substr_count($violations, 'runtime schema mutation or escape surface is forbidden'));
        self::assertSame(1, substr_count($violations, 'deferred runtime schema mutation or escape through $schema is forbidden'));
        self::assertStringContainsString('aliasing the Schema facade as DatabaseSchema is forbidden', $violations);
    }

    public function test_raw_connection_sql_surface_is_read_only_or_fails_closed(): void
    {
        $expectedRawMethods = [
            'affectingStatement',
            'cursor',
            'delete',
            'insert',
            'scalar',
            'select',
            'selectFromWriteConnection',
            'selectOne',
            'selectResultSets',
            'statement',
            'unprepared',
            'update',
        ];
        $rawMethods = array_values(array_filter(
            get_class_methods(Connection::class),
            static fn (string $method): bool => preg_match('/^(?:select|insert|update|delete|statement|affectingStatement|unprepared|scalar|cursor)/', $method) === 1,
        ));
        sort($rawMethods, SORT_STRING);
        self::assertSame($expectedRawMethods, $rawMethods, 'Laravel Connection raw SQL surface changed; update architecture attribution deliberately.');

        $this->write('app/Modules/Orders/Application/RawConnectionReads.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
final class RawConnectionReads
{
    public function __construct(
        private Connection $connection,
        private DatabaseManager $database,
    ) {}

    public function safe(Connection $parameter): void
    {
        $this->connection->selectOne('SELECT 1 AS ready');
        $this->database->select('SELECT COUNT(*) AS total FROM orders');
        $this->database->connection()->selectOne('SELECT 1');
        $parameter->scalar('SELECT COUNT(*) FROM orders');
        DB::selectOne('SELECT 1');
    }

    public function unsafe(Connection $parameter, string $sql): void
    {
        $this->connection->selectOne('UPDATE orders SET state = "paid"');
        $this->database->select('SELECT 1; DELETE FROM orders');
        $this->database->connection()->selectOne('ALTER TABLE orders ADD COLUMN bad INT');
        $parameter->cursor($sql);
        $parameter->selectOne("SELECT 1 INTO OUTFILE '/tmp/architecture-bypass'");
        $this->connection->update('UPDATE orders SET state = "paid"');
        $this->database->delete('DELETE FROM orders');
        DB::selectOne('DROP TABLE orders');
        $local = $this->database->connection();
        $local->selectOne('DELETE FROM orders');
        $facadeConnection = DB::connection();
        $facadeConnection->selectOne('UPDATE orders SET state = "paid"');
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/AliasedConnection.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Database\Connection as SqlConnection;
final class AliasedConnection
{
    public function unsafe(SqlConnection $db): void
    {
        $db->selectOne('DELETE FROM orders');
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/InterfaceConnection.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Database\ConnectionInterface;
final class InterfaceConnection
{
    public function unsafe(ConnectionInterface $db): void
    {
        $db->selectOne('TRUNCATE TABLE orders');
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/NullableConnection.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Database\Connection;
final class NullableConnection
{
    public function __construct(private ?Connection $connection) {}

    public function unsafe(Connection|null $parameter): void
    {
        $this->connection?->selectOne('DELETE FROM orders');
        $parameter?->update('UPDATE orders SET state = "paid"');
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(11, substr_count($violations, 'raw Connection read API'));
        self::assertSame(3, substr_count($violations, 'raw Connection mutation API'));
        self::assertStringContainsString('read API cursor must receive one literal read-only SELECT statement', $violations);
        self::assertStringContainsString('read API selectOne must receive one literal read-only SELECT statement', $violations);
    }

    public function test_mutation_of_unmapped_literal_table_fails_closed(): void
    {
        $this->write('app/Modules/Orders/Application/UnknownOwner.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class UnknownOwner { public function run($db): void { $db->table('orphan_table')->delete(); } }
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('mutation of unmapped durable table candidate orphan_table', $violations);
    }

    public function test_db_facade_alias_and_direct_runtime_pdo_access_fail_closed(): void
    {
        $this->write('app/Modules/Orders/Application/OpaqueAccess.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Support\Facades\DB as Database;
final class OpaqueAccess
{
    public function run($db): void
    {
        Database::table('orders')->delete();
        $db->getPdo();
        new \PDO('sqlite::memory:');
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('aliasing the DB facade as Database is forbidden', $violations);
        self::assertSame(2, substr_count($violations, 'direct PDO access is forbidden'));
    }

    public function test_persistence_exception_must_be_exact_used_and_non_stale(): void
    {
        $path = 'app/Modules/Customers/Application/LegacyWrite.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Customers\Application;
final class LegacyWrite { public function run($db): void { $db->table('orders')->update(['state' => 'paid']); } }
PHP);

        $accepted = $this->checker([$path.'|orders'])->check()['violations'];
        self::assertSame([], $accepted);

        $stale = implode("\n", $this->checker([$path.'|orders', 'app/Modules/Customers/Application/Removed.php|orders'])->check()['violations']);
        self::assertStringContainsString('stale/unused entry', $stale);
    }

    /**
     * @param  list<string>  $exceptions
     * @param  list<string>  $migrationTriggerDdlHelpers
     */
    private function checker(array $exceptions = [], array $migrationTriggerDdlHelpers = []): ArchitectureBoundaryChecker
    {
        return new ArchitectureBoundaryChecker($this->root, [
            'allowed_module_dependencies' => [],
            'domain_dependency_exceptions' => [],
            'cycle_exceptions' => [],
            'durable_table_owners' => [
                'custom_plan_policy_tags' => 'Catalog',
                'custom_plan_policy_tiers' => 'Catalog',
                'orders' => 'Orders',
            ],
            'persistence_exceptions' => $exceptions,
            'migration_trigger_ddl_helpers' => $migrationTriggerDdlHelpers,
        ]);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->root.'/'.$relativePath;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content."\n");
    }
}
