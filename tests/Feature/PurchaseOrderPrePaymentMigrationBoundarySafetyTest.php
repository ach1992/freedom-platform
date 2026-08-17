<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class PurchaseOrderPrePaymentMigrationBoundarySafetyTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                $this->truncateDatabaseTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_interrupted_000100_and_000101_boundaries_reject_settlement_backed_lifecycle_skip(): void
    {
        /** @var Migration $reconcile */
        $reconcile = require database_path('migrations/2026_08_17_000100_reconcile_pre_payment_order_authority.php');
        /** @var Migration $splitShape */
        $splitShape = require database_path('migrations/2026_08_17_000101_split_pre_payment_order_shape_constraints.php');
        /** @var Migration $finalInsertGuard */
        $finalInsertGuard = require database_path('migrations/2026_08_17_000102_harden_purchase_order_insert_lifecycle_authority.php');

        $settlement = $this->createPurchaseOrderSettlement('prepayment-migration-boundary');
        $settlementRow = DB::table('purchase_settlements')->where('id', $settlement->settlementId)->first();
        self::assertNotNull($settlementRow);
        $intent = DB::table('payment_intents')->where('id', $settlementRow->payment_intent_id)->first();
        self::assertNotNull($intent);
        $quote = DB::table('quotes')->where('id', $settlementRow->source_quote_id)->first();
        self::assertNotNull($quote);

        try {
            self::assertTrue($this->triggerExists('orders_purchase_lifecycle_insert_fence'));

            // Re-enter 000100 exactly as a restartable migration would. Its legacy main insert
            // trigger is deliberately weaker at this boundary; the pre-DDL 000099 fence must
            // independently keep the committed database fail-closed.
            $reconcile->up();
            self::assertTrue($this->triggerExists('orders_purchase_lifecycle_insert_fence'));
            $this->assertForgedProvisioningQueuedInsertRejected($settlementRow, $intent, $quote, 'after-000100');

            // 000101 further relaxes/splits CHECK constraints. A crash here must still leave the
            // independent lifecycle fence authoritative before 000102 reasserts the main trigger.
            $splitShape->up();
            self::assertTrue($this->triggerExists('orders_purchase_lifecycle_insert_fence'));
            $this->assertForgedProvisioningQueuedInsertRejected($settlementRow, $intent, $quote, 'after-000101');
        } finally {
            $finalInsertGuard->up();
        }

        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('order_items')->count());
        self::assertSame(0, DB::table('order_state_histories')->count());
    }

    private function assertForgedProvisioningQueuedInsertRejected(
        object $settlement,
        object $intent,
        object $quote,
        string $suffix,
    ): void {
        try {
            DB::table('orders')->insert([
                'public_id' => (string) Str::ulid(),
                'source_type' => 'purchase',
                'purchase_settlement_id' => $settlement->id,
                'purchase_settlement_public_id' => $settlement->public_id,
                'payment_intent_id' => $intent->id,
                'payment_intent_public_id' => $intent->public_id,
                'user_id' => $settlement->user_id,
                'source_quote_id' => $quote->id,
                'source_quote_public_id' => $quote->public_id,
                'source_quote_configuration_hash' => $quote->configuration_snapshot_hash,
                'state' => 'provisioning_queued',
                'state_version' => 2,
                'total_amount_irr' => $intent->amount_irr,
                'settled_amount_irr' => $settlement->amount_irr,
                'currency' => $settlement->currency,
                'paid_at' => $settlement->settled_at,
                'creation_correlation_id' => $this->purchaseOrderCorrelation('migration-boundary-'.$suffix),
                'created_at' => $this->purchaseOrderTimestamp(),
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Interrupted migration boundary accepted a settlement-backed lifecycle skip.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
}
