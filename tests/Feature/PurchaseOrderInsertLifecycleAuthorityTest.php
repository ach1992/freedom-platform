<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class PurchaseOrderInsertLifecycleAuthorityTest extends TestCase
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

    public function test_settlement_backed_direct_insert_cannot_skip_paid_v1_lifecycle_authority(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('insert_lifecycle_guard');
        $settlementRow = DB::table('purchase_settlements')->where('id', $settlement->settlementId)->first();
        self::assertNotNull($settlementRow);
        $intent = DB::table('payment_intents')->where('id', $settlementRow->payment_intent_id)->first();
        self::assertNotNull($intent);
        $quote = DB::table('quotes')->where('id', $settlementRow->source_quote_id)->first();
        self::assertNotNull($quote);

        try {
            DB::table('orders')->insert([
                'public_id' => (string) Str::ulid(),
                'source_type' => 'purchase',
                'purchase_settlement_id' => $settlementRow->id,
                'purchase_settlement_public_id' => $settlementRow->public_id,
                'payment_intent_id' => $intent->id,
                'payment_intent_public_id' => $intent->public_id,
                'user_id' => $settlementRow->user_id,
                'source_quote_id' => $quote->id,
                'source_quote_public_id' => $quote->public_id,
                'source_quote_configuration_hash' => $quote->configuration_snapshot_hash,
                'state' => 'provisioning_queued',
                'state_version' => 2,
                'total_amount_irr' => $intent->amount_irr,
                'settled_amount_irr' => $settlementRow->amount_irr,
                'currency' => 'IRR',
                'paid_at' => $settlementRow->settled_at,
                'creation_correlation_id' => $this->purchaseOrderCorrelation('insert-lifecycle-forgery'),
                'created_at' => $this->purchaseOrderTimestamp(),
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Expected settlement-backed direct insert outside paid/v1 to fail closed.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('order_items')->count());
        self::assertSame(0, DB::table('order_state_histories')->count());
    }
}
