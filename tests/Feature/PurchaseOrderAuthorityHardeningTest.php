<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Application\WalletTopUpPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchaseOrderAuthorityHardeningTest extends TestCase
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
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_order_schema_reserves_known_typed_sources_and_makes_source_quote_unique(): void
    {
        $sourceConstraint = DB::selectOne(
            'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['orders', 'orders_source_type_chk'],
        );
        self::assertNotNull($sourceConstraint);
        $clause = strtolower((string) $sourceConstraint->CHECK_CLAUSE);
        self::assertStringContainsString("'purchase'", $clause);
        self::assertStringContainsString("'trial'", $clause);
        self::assertStringContainsString("'gift'", $clause);
        self::assertStringContainsString("'service_code'", $clause);
        self::assertStringContainsString("'benefit_code'", $clause);
        self::assertStringContainsString("'admin_grant'", $clause);

        $quoteIndex = DB::selectOne(
            'SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['orders', 'orders_source_quote_unique'],
        );
        self::assertNotNull($quoteIndex);
        self::assertSame(0, (int) $quoteIndex->NON_UNIQUE);

        $userId = $this->quoteUser('customer');
        $this->assertQueryRejected(fn (): bool => DB::table('orders')->insert([
            'public_id' => (string) Str::ulid(),
            'source_type' => 'trial',
            'purchase_settlement_id' => null,
            'purchase_settlement_public_id' => null,
            'payment_intent_id' => null,
            'payment_intent_public_id' => null,
            'user_id' => $userId,
            'source_quote_id' => null,
            'source_quote_public_id' => null,
            'source_quote_configuration_hash' => null,
            'state' => 'draft',
            'state_version' => 1,
            'total_amount_irr' => 0,
            'currency' => 'IRR',
            'paid_at' => null,
            'creation_correlation_id' => $this->purchaseOrderCorrelation('reserved-trial-source'),
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]));
        self::assertSame(0, DB::table('orders')->count());
    }

    public function test_second_authoritative_settlement_for_same_quote_cannot_create_second_order_and_capture_stays_successful(): void
    {
        $firstSettlement = $this->createPurchaseOrderSettlement('quote_reuse');
        $orders = $this->app->make(PurchaseOrderService::class);
        $firstOrder = $orders->createFromSettlement(
            $firstSettlement->settlementPublicId,
            $this->purchaseOrderCorrelation('quote-reuse-first-order'),
        );

        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'purchase.order.eligibility.quote_reuse.second',
            $firstSettlement->userId,
            $firstSettlement->sourceQuotePublicId,
        );
        $secondIntent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'purchase.order.intent.quote_reuse.second',
            $firstSettlement->userId,
            $firstSettlement->sourceQuotePublicId,
            $decision->publicId,
            $firstSettlement->providerCode,
            $this->purchaseOrderCorrelation('quote-reuse-second-intent'),
        );
        DB::table('payment_intents')->where('public_id', $secondIntent->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $secondSettlement = $this->app->make(PurchaseSettlementService::class)->capture(
            $secondIntent->intentPublicId,
            $firstSettlement->providerCode,
            $this->purchaseEvent('quote-reuse-second', $firstSettlement->amount->amount()),
            $this->purchaseOrderCorrelation('quote-reuse-second-settlement'),
        );
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $secondIntent->intentPublicId)->value('state'));
        self::assertSame(2, DB::table('purchase_settlements')->count());

        $this->assertQueryRejected(fn (): mixed => $orders->createFromSettlement(
            $secondSettlement->settlementPublicId,
            $this->purchaseOrderCorrelation('quote-reuse-second-order'),
        ));

        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $secondIntent->intentPublicId)->value('state'));
        self::assertSame(2, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame($firstOrder->orderId, (int) DB::table('orders')->value('id'));
        self::assertSame(1, DB::table('order_items')->count());
        self::assertSame(1, DB::table('order_state_histories')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'order.purchase.created')->count());
    }

    public function test_real_wallet_top_up_intent_cannot_be_substituted_as_purchase_order_payment_authority(): void
    {
        $purchaseSettlement = $this->createPurchaseOrderSettlement('wallet_guard');
        $settlement = DB::table('purchase_settlements')->where('id', $purchaseSettlement->settlementId)->first();
        self::assertNotNull($settlement);
        $quote = DB::table('quotes')->where('id', $settlement->source_quote_id)->first();
        self::assertNotNull($quote);

        $walletAccountId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.order.guard.'.$purchaseSettlement->userId,
            'account_class' => 'liability',
            'owner_user_id' => $purchaseSettlement->userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        $walletPayments = $this->app->make(WalletTopUpPaymentService::class);
        $walletIntent = $walletPayments->create(
            'topup.intent.order.guard.000001',
            $purchaseSettlement->userId,
            $walletAccountId,
            'fake_gateway',
            Money::irr($purchaseSettlement->amount->amount()),
            $this->purchaseOrderCorrelation('wallet-guard-intent'),
        );
        $walletSettlement = $walletPayments->capture(
            $walletIntent->intentPublicId,
            'fake_gateway',
            $this->purchaseEvent('wallet-top-up-authority', $purchaseSettlement->amount->amount()),
            $this->purchaseOrderCorrelation('wallet-guard-capture'),
        );
        self::assertSame('captured', $walletSettlement->state->value);
        self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
        $walletIntentId = (int) DB::table('payment_intents')->where('public_id', $walletIntent->intentPublicId)->value('id');

        $this->assertQueryRejected(fn (): bool => DB::table('orders')->insert([
            'public_id' => (string) Str::ulid(),
            'source_type' => 'purchase',
            'purchase_settlement_id' => $settlement->id,
            'purchase_settlement_public_id' => $settlement->public_id,
            'payment_intent_id' => $walletIntentId,
            'payment_intent_public_id' => $walletIntent->intentPublicId,
            'user_id' => $purchaseSettlement->userId,
            'source_quote_id' => $quote->id,
            'source_quote_public_id' => $quote->public_id,
            'source_quote_configuration_hash' => $quote->configuration_snapshot_hash,
            'state' => 'paid',
            'state_version' => 1,
            'total_amount_irr' => $quote->final_price_irr,
            'settled_amount_irr' => $settlement->amount_irr,
            'currency' => 'IRR',
            'paid_at' => $settlement->settled_at,
            'creation_correlation_id' => $this->purchaseOrderCorrelation('wallet-guard-forgery'),
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]));

        self::assertSame('wallet_top_up', DB::table('payment_intents')->where('id', $walletIntentId)->value('purpose'));
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(1, DB::table('purchase_settlements')->count());
    }

    private function purchaseEvent(string $suffix, int $amountIrr): VerifiedPaymentEvent
    {
        $eventId = 'evt-order-'.$suffix;
        $transactionId = 'txn-order-'.$suffix;

        return new VerifiedPaymentEvent(
            $eventId,
            hash('sha256', 'purchase-order-provider-event:'.$suffix),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $transactionId,
                $eventId,
                Money::irr($amountIrr),
                $this->purchaseOrderClock->value,
                $this->purchaseOrderClock->value,
                hash('sha256', 'purchase-order-provider-evidence:'.$suffix),
                ['provider_reference' => $transactionId],
            ),
        );
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
