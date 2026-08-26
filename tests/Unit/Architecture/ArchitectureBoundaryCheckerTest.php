<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\ArchitectureBoundaryChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/ArchitectureBoundaryChecker.php';

final class ArchitectureBoundaryCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-architecture-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_declared_public_cross_module_dependency_is_allowed(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Application\PaymentService;
PHP);

        $result = $this->checker(['Orders' => ['Payments']])->check();

        self::assertSame([], $result['violations']);
        self::assertSame(['Orders>Payments'], $result['edges']);
    }

    public function test_undeclared_cross_module_dependency_is_rejected_with_path(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Application\PaymentService;
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('app/Modules/Orders/Application/Checkout.php:3 undeclared module dependency Orders -> Payments', implode("\n", $result['violations']));
    }

    public function test_fully_qualified_cross_module_dependency_cannot_bypass_the_checker(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class Checkout
{
    public function payment(): \App\Modules\Payments\Application\PaymentService
    {
        throw new \RuntimeException('not executed');
    }
}
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('undeclared module dependency Orders -> Payments', implode("\n", $result['violations']));
    }

    public function test_cross_module_infrastructure_import_is_rejected(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Infrastructure\GatewayClient;
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('cross-module import of Payments\Infrastructure is forbidden', implode("\n", $result['violations']));
    }

    public function test_application_cannot_depend_on_its_own_infrastructure_layer(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Orders\Infrastructure\OrderRepository;
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('Application may not depend on its own Infrastructure layer', implode("\n", $result['violations']));
    }

    public function test_domain_framework_and_other_layer_imports_are_rejected(): void
    {
        $this->write('app/Modules/Orders/Domain/Order.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Domain;
use Illuminate\Support\Collection;
use App\Modules\Orders\Application\OrderService;
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('Domain may not reference framework infrastructure', $violations);
        self::assertStringContainsString('Domain may depend only on its own Domain', $violations);
    }

    public function test_shared_code_cannot_reference_feature_modules_with_or_without_imports(): void
    {
        $this->write('app/Shared/Application/SharedService.php', <<<'PHP'
<?php
namespace App\Shared\Application;
use App\Modules\Orders\Application\OrderService;
final class SharedService
{
    public function second(): \App\Modules\Payments\Application\PaymentService
    {
        throw new \RuntimeException('not executed');
    }
}
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('Shared code must not depend on a feature module', $violations);
        self::assertGreaterThanOrEqual(2, substr_count($violations, 'Shared code must not depend on a feature module'));
    }

    public function test_module_cycles_are_rejected(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Application\PaymentService;
PHP);
        $this->write('app/Modules/Payments/Application/PaymentService.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use App\Modules\Orders\Domain\OrderState;
PHP);

        $result = $this->checker([
            'Orders' => ['Payments'],
            'Payments' => ['Orders'],
        ])->check();

        self::assertStringContainsString('Module dependency cycle detected: Orders <-> Payments', implode("\n", $result['violations']));
    }

    public function test_presentation_and_routes_cannot_mutate_tables_directly(): void
    {
        $this->write('app/Modules/Orders/Presentation/Http/OrderController.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Presentation\Http;
final class OrderController { public function __invoke($db): void { $db->table('orders')->update(['state' => 'paid']); } }
PHP);
        $this->write('routes/console.php', <<<'PHP'
<?php
DB::table('worker_heartbeats')->updateOrInsert(['worker_id' => 'scheduler'], []);
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('direct persistence mutation of orders from Presentation is forbidden', $violations);
        self::assertStringContainsString('direct persistence mutation of worker_heartbeats from routes is forbidden', $violations);
    }

    public function test_exact_durable_owner_rejects_cross_module_mutation(): void
    {
        $this->write('app/Modules/Payments/Application/UnsafeLedgerWrite.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class UnsafeLedgerWrite { public function run($db): void { $db->table('ledger_entries')->insert(['amount_irr' => 1]); } }
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('Payments mutation of durable table ledger_entries owned by Wallet is forbidden', implode("\n", $result['violations']));
    }

    public function test_shared_persistence_is_checked_against_the_same_owner_map(): void
    {
        $this->write('app/Shared/Infrastructure/OutboxStore.php', <<<'PHP'
<?php
namespace App\Shared\Infrastructure;
final class OutboxStore { public function run($db): void { $db->table('outbox_messages')->update(['state' => 'ready']); } }
PHP);

        $result = $this->checker()->check();

        self::assertSame([], $result['violations']);
    }

    public function test_dynamic_table_mutation_fails_closed_but_dynamic_read_is_not_misclassified(): void
    {
        $this->write('app/Modules/Orders/Application/DynamicWrite.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class DynamicWrite { public function run($db, string $table): void { $db->table($table)->update(['state' => 'paid']); } }
PHP);
        $this->write('app/Modules/Orders/Application/DynamicRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class DynamicRead { public function run($db, string $table): mixed { return $db->table($table)->first(); } }
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('dynamic table mutation is forbidden because durable-table ownership cannot be attributed', $violations);
        self::assertSame(1, substr_count($violations, 'dynamic table mutation is forbidden'));
    }

    public function test_eloquent_persistence_is_explicitly_prohibited_in_modules_and_shared_code(): void
    {
        $this->write('app/Modules/Orders/Infrastructure/OrderRecord.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Infrastructure;
use Illuminate\Database\Eloquent\Model;
final class OrderRecord extends Model {}
PHP);
        $this->write('app/Shared/Infrastructure/SharedRecord.php', <<<'PHP'
<?php
namespace App\Shared\Infrastructure;
use Illuminate\Database\Eloquent\Model;
final class SharedRecord extends Model {}
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('feature modules may not reference Eloquent persistence primitives', $violations);
        self::assertStringContainsString('Shared code may not reference Eloquent persistence primitives', $violations);
    }

    public function test_opaque_raw_persistence_is_rejected_from_presentation(): void
    {
        $this->write('app/Modules/Orders/Presentation/Http/UnsafeController.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Presentation\Http;
use Illuminate\Support\Facades\DB;
final class UnsafeController { public function run(): void { DB::statement('DELETE FROM orders'); } }
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('opaque persistence API statement from Presentation is forbidden', implode("\n", $result['violations']));
    }

    /** @param array<string,list<string>> $allowed */
    private function checker(array $allowed = []): ArchitectureBoundaryChecker
    {
        return new ArchitectureBoundaryChecker($this->root, [
            'allowed_module_dependencies' => $allowed,
            'cycle_exceptions' => [],
            'durable_table_owners' => [
                'ledger_entries' => 'Wallet',
                'orders' => 'Orders',
                'outbox_messages' => 'Shared',
                'worker_heartbeats' => 'Operations',
            ],
            'persistence_exceptions' => [],
        ]);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->root.'/'.$relativePath;
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, $content."\n");
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
