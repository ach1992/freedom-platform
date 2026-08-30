<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\ArchitectureBoundaryChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/ArchitectureBoundaryChecker.php';

final class ArchitectureMetadataGrantIntrospectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-metadata-grants-'.bin2hex(random_bytes(8));
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

    public function test_exact_literal_show_grants_is_allowed_only_in_reviewed_telegram_authority_paths(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryForeignKeyMetadataAttestor.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Illuminate\Database\Connection;
final class TelegramDeliveryForeignKeyMetadataAttestor
{
    public function grants(Connection $connection): array
    {
        return $connection->select('SHOW GRANTS FOR CURRENT_USER', [], false);
    }
}
PHP);

        self::assertSame([], $this->checker()->check()['violations']);

        $this->write('app/Modules/Telegram/Application/TelegramDeliveryLifecycleDatabaseAuthority.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Illuminate\Database\Connection;
final class TelegramDeliveryLifecycleDatabaseAuthority
{
    public function grants(Connection $connection): array
    {
        return $connection->select('SHOW GRANTS FOR CURRENT_USER', [], false);
    }
}
PHP);

        self::assertSame([], $this->checker()->check()['violations']);
    }

    public function test_same_show_grants_elsewhere_is_rejected(): void
    {
        $this->write('app/Modules/Orders/Application/UnsafeGrantIntrospection.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Database\Connection;
final class UnsafeGrantIntrospection
{
    public function grants(Connection $connection): array
    {
        return $connection->select('SHOW GRANTS FOR CURRENT_USER', [], false);
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('UnsafeGrantIntrospection.php', $violations);
        self::assertStringContainsString('exact reviewed metadata introspection', $violations);
    }

    public function test_variant_or_dynamic_show_grants_fails_closed_even_in_reviewed_path(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryForeignKeyMetadataAttestor.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Illuminate\Database\Connection;
final class TelegramDeliveryForeignKeyMetadataAttestor
{
    public function unsafe(Connection $connection, string $sql): array
    {
        $connection->select('SHOW GRANTS FOR PUBLIC', [], false);

        return $connection->select($sql, [], false);
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(2, substr_count($violations, 'exact reviewed metadata introspection'));
    }

    private function checker(): ArchitectureBoundaryChecker
    {
        return new ArchitectureBoundaryChecker($this->root, [
            'allowed_module_dependencies' => [],
            'domain_dependency_exceptions' => [],
            'cycle_exceptions' => [],
            'durable_table_owners' => [],
            'persistence_exceptions' => [],
            'migration_trigger_ddl_helpers' => [],
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
