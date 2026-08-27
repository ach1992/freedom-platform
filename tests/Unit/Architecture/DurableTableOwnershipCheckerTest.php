<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\DurableTableOwnershipChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/DurableTableOwnershipChecker.php';

final class DurableTableOwnershipCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-table-ownership-'.bin2hex(random_bytes(8));
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

    public function test_new_schema_table_without_owner_fails_closed(): void
    {
        $this->migration('2026_01_01_000000_test.php', <<<'PHP'
<?php
Schema::create('orders', function ($table): void {});
PHP);

        $violations = (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [],
        ]))->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('durable table orders has no explicit architecture owner/classification', $violations[0]);
    }

    public function test_owned_schema_and_raw_tables_are_accepted(): void
    {
        $this->migration('2026_01_01_000000_test.php', <<<'PHP'
<?php
Schema::create('orders', function ($table): void {});
DB::statement('CREATE TABLE `audit_archive` (`id` BIGINT NOT NULL)');
PHP);

        $violations = (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'orders' => 'Orders',
                'audit_archive' => 'Shared',
            ],
        ]))->violations();

        self::assertSame([], $violations);
    }

    public function test_restartable_alternative_creation_paths_share_one_owner(): void
    {
        $this->migration('2026_01_01_000000_bootstrap.php', <<<'PHP'
<?php
if (! Schema::hasTable('service_subscriptions')) {
    DB::statement('CREATE TABLE service_subscriptions (`id` BIGINT NOT NULL)');
}
PHP);
        $this->migration('2026_01_01_000001_authority.php', <<<'PHP'
<?php
if (! Schema::hasTable('service_subscriptions')) {
    Schema::create('service_subscriptions', function ($table): void {});
}
PHP);

        $violations = (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'service_subscriptions' => 'Provisioning',
            ],
        ]))->violations();

        self::assertSame([], $violations);
    }

    public function test_sql_support_file_create_table_is_inventoried(): void
    {
        $this->migration('support/service-delivery-effects.sql', <<<'SQL'
CREATE TABLE service_delivery_effects (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY
);
SQL);

        $missing = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [],
        ]))->violations());
        self::assertStringContainsString('durable table service_delivery_effects has no explicit architecture owner', $missing);

        $accepted = (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'service_delivery_effects' => 'Provisioning',
            ],
        ]))->violations();
        self::assertSame([], $accepted);
    }

    public function test_aliased_schema_or_blueprint_import_fails_closed(): void
    {
        $this->migration('2026_01_01_000000_alias.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
PHP);

        $violations = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [],
        ]))->violations());

        self::assertStringContainsString('aliasing Schema facade is forbidden', $violations);
        self::assertStringContainsString('aliasing Blueprint is forbidden', $violations);
    }

    public function test_table_rename_requires_explicit_ownership_lifecycle_support(): void
    {
        $this->migration('2026_01_01_000000_schema_rename.php', <<<'PHP'
<?php
Schema::create('orders', function ($table): void {});
Schema::rename('orders', 'orders_archive');
PHP);
        $this->migration('support/raw-rename.sql', <<<'SQL'
RENAME TABLE orders TO orders_archive;
ALTER TABLE orders_archive RENAME TO orders_final;
SQL);

        $violations = implode("\n", (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'orders' => 'Orders',
            ],
        ]))->violations());

        self::assertStringContainsString('durable table rename via Schema::rename is not ownership-attributable', $violations);
        self::assertStringContainsString('durable table rename via raw RENAME TABLE is not ownership-attributable', $violations);
        self::assertStringContainsString('durable table rename via raw ALTER TABLE ... RENAME is not ownership-attributable', $violations);
    }

    public function test_stale_owner_entry_is_rejected(): void
    {
        $this->migration('2026_01_01_000000_test.php', <<<'PHP'
<?php
Schema::create('orders', function ($table): void {});
PHP);

        $violations = (new DurableTableOwnershipChecker($this->root, [
            'durable_table_owners' => [
                'orders' => 'Orders',
                'removed_table' => 'Orders',
            ],
        ]))->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('stale/undiscovered table removed_table', $violations[0]);
    }

    private function migration(string $file, string $content): void
    {
        $path = $this->root.'/database/migrations/'.$file;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content."\n");
    }
}
