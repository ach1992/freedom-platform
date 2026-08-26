<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\ArchitectureBoundaryChecker;
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

        $accepted = $this->checker([], [$path])->check()['violations'];
        self::assertSame([], $accepted);

        $stale = implode("\n", $this->checker([], [$path, 'app/Modules/Orders/Infrastructure/RemovedGuard.php'])->check()['violations']);
        self::assertStringContainsString('migration_ddl_helpers contains stale/unused helper', $stale);

        $this->write('app/Modules/Orders/Infrastructure/RuntimeCaller.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Infrastructure;
final class RuntimeCaller { public function run(): void { OrderMigrationGuard::drop(); } }
PHP);
        $runtimeReference = implode("\n", $this->checker([], [$path])->check()['violations']);
        self::assertStringContainsString('migration-only DDL helper', $runtimeReference);
        self::assertStringContainsString('may not be referenced from runtime source', $runtimeReference);
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
     * @param  list<string>  $migrationDdlHelpers
     */
    private function checker(array $exceptions = [], array $migrationDdlHelpers = []): ArchitectureBoundaryChecker
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
            'migration_ddl_helpers' => $migrationDdlHelpers,
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
