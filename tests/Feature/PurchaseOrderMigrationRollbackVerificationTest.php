<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** @requirement BUY-001 DAT-003 DAT-004 QUA-004 */
final class PurchaseOrderMigrationRollbackVerificationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use PurchaseOrderTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_order_hardening_down_refuses_before_dropping_constraints_when_any_order_exists(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('rollback_guard');
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('rollback-guard-order'),
        );
        self::assertSame(1, DB::table('orders')->count());

        /** @var \Illuminate\Database\Migrations\Migration $migration */
        $migration = require database_path('migrations/2026_08_14_001161_harden_order_source_and_quote_uniqueness.php');

        try {
            $migration->down();
            self::fail('Expected Order hardening rollback to refuse while Orders exist.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('while Orders exist', $exception->getMessage());
        }

        $quoteIndex = DB::selectOne(
            'SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['orders', 'orders_source_quote_unique'],
        );
        self::assertNotNull($quoteIndex);
        self::assertSame(0, (int) $quoteIndex->NON_UNIQUE);

        $sourceConstraint = DB::selectOne(
            'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['orders', 'orders_source_type_chk'],
        );
        self::assertNotNull($sourceConstraint);
        $clause = strtolower((string) $sourceConstraint->CHECK_CLAUSE);
        self::assertStringContainsString("'trial'", $clause);
        self::assertStringContainsString("'admin_grant'", $clause);
        self::assertSame(1, DB::table('orders')->count());
    }
}
