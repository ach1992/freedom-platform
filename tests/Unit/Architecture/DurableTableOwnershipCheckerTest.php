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
        file_put_contents($this->root.'/database/migrations/'.$file, $content."\n");
    }
}
