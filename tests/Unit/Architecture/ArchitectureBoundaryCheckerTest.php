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

    public function test_protected_table_cross_module_mutation_is_rejected(): void
    {
        $this->write('app/Modules/Payments/Application/UnsafeLedgerWrite.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class UnsafeLedgerWrite { public function run($db): void { $db->table('ledger_entries')->insert(['amount_irr' => 1]); } }
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('Payments mutation of protected table ledger_entries owned by Wallet is forbidden', implode("\n", $result['violations']));
    }

    public function test_opaque_raw_persistence_is_rejected_when_table_ownership_cannot_be_attributed(): void
    {
        $this->write('app/Modules/Orders/Application/UnsafeSql.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Support\Facades\DB;
final class UnsafeSql { public function run(): void { DB::statement('DELETE FROM orders'); } }
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('opaque persistence API statement is forbidden', implode("\n", $result['violations']));
    }

    /** @param array<string,list<string>> $allowed */
    private function checker(array $allowed = []): ArchitectureBoundaryChecker
    {
        return new ArchitectureBoundaryChecker($this->root, [
            'allowed_module_dependencies' => $allowed,
            'cycle_exceptions' => [],
            'protected_table_owners' => [
                '#^ledger_#' => 'Wallet',
                '#^worker_heartbeats$#' => 'Operations',
            ],
            'persistence_exceptions' => [],
            'opaque_persistence_exceptions' => [],
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
