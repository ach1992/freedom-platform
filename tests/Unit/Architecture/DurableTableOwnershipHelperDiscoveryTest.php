<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\DurableTableOwnershipChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/DurableTableOwnershipChecker.php';

final class DurableTableOwnershipHelperDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-helper-ownership-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/database/migrations', 0775, true);
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

    public function test_helper_created_literal_tables_are_owned_and_non_literal_calls_fail_closed(): void
    {
        $this->migration(<<<'PHP'
<?php
final class ProbeMigration
{
    public function up(): void
    {
        $this->createHistoryTable('panel_protocol_profile_histories');
        $this->createHistoryTable('panel_service_target_histories');
    }

    private function createHistoryTable(string $tableName): void
    {
        Schema::create($tableName, function ($table): void {});
    }
}
PHP);

        $missing = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [],
        ]))->violations());
        self::assertStringContainsString('durable table panel_protocol_profile_histories has no explicit architecture owner', $missing);
        self::assertStringContainsString('durable table panel_service_target_histories has no explicit architecture owner', $missing);

        $accepted = (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'panel_protocol_profile_histories' => 'Panels',
                'panel_service_target_histories' => 'Panels',
            ],
        ]))->violations();
        self::assertSame([], $accepted);

        $this->migration(<<<'PHP'
<?php
final class ProbeMigration
{
    public function up(string $runtimeName): void
    {
        $this->createHistoryTable($runtimeName);
    }

    private function createHistoryTable(string $tableName): void
    {
        Schema::create($tableName, function ($table): void {});
    }
}
PHP);
        $dynamic = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [],
        ]))->violations());
        self::assertStringContainsString('non-literal table callsite', $dynamic);
    }

    public function test_blueprint_literal_and_literal_helper_calls_are_inventoried(): void
    {
        $this->migration(<<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
final class ProbeMigration
{
    public function up(): void
    {
        $literal = new Blueprint('mysql', 'order_source_authorizations');
        $literal->create();
        $this->createTable('agent_bulk_orders');
        $this->createTable('agent_bulk_order_items');
    }

    private function createTable(string $table): void
    {
        $blueprint = new Blueprint('mysql', $table);
        $blueprint->create();
    }
}
PHP);

        $missing = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [],
        ]))->violations());
        self::assertStringContainsString('durable table order_source_authorizations has no explicit architecture owner', $missing);
        self::assertStringContainsString('durable table agent_bulk_orders has no explicit architecture owner', $missing);
        self::assertStringContainsString('durable table agent_bulk_order_items has no explicit architecture owner', $missing);

        $accepted = (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'order_source_authorizations' => 'Orders',
                'agent_bulk_orders' => 'Orders',
                'agent_bulk_order_items' => 'Orders',
            ],
        ]))->violations();
        self::assertSame([], $accepted);
    }

    public function test_fully_qualified_blueprint_is_discovered_and_unresolved_construction_fails_closed(): void
    {
        $this->migration(<<<'PHP'
<?php
final class ProbeMigration
{
    public function up(string $prefix): void
    {
        $literal = new \Illuminate\Database\Schema\Blueprint('mysql', 'order_source_authorizations');
        $literal->create();
        $dynamic = new \Illuminate\Database\Schema\Blueprint('mysql', $prefix.'_events');
        $dynamic->create();
    }
}
PHP);

        $violations = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'order_source_authorizations' => 'Orders',
            ],
        ]))->violations());

        self::assertStringContainsString('Blueprint table creation could not be statically resolved to a literal table set', $violations);
        self::assertStringNotContainsString('stale/undiscovered table order_source_authorizations', $violations);
    }

    public function test_blueprint_helper_non_literal_table_call_fails_closed(): void
    {
        $this->migration(<<<'PHP'
<?php
use Illuminate\Database\Schema\Blueprint;
final class ProbeMigration
{
    public function up(string $runtimeTable): void
    {
        $this->createTable($runtimeTable);
    }

    private function createTable(string $table): void
    {
        $blueprint = new Blueprint('mysql', $table);
        $blueprint->create();
    }
}
PHP);

        $violations = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [],
        ]))->violations());

        self::assertStringContainsString('Blueprint helper createTable has a non-literal table callsite', $violations);
    }

    private function migration(string $content): void
    {
        file_put_contents($this->root.'/database/migrations/2026_01_01_000000_probe.php', $content."\n");
    }
}
