<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseRefundReceipt;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-008 QUA-004 */
final class InitialProvisioningRepeatedRefundInvalidationTest extends TestCase
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

    public function test_repeated_purchase_refunds_reuse_first_monotonic_provisioning_invalidation(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('repeated-refund-invalidation');
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-repeated-refund-invalidation'),
        );

        $totalAmount = $settlement->amount->amount();
        self::assertGreaterThan(1, $totalAmount);
        $firstAmount = intdiv($totalAmount, 2);
        $secondAmount = $totalAmount - $firstAmount;

        $firstRefund = $this->recordRefund($settlement, 'first', $firstAmount, 5);
        self::assertSame(PaymentIntentState::PartiallyRefunded, $firstRefund->state);

        $firstInvalidation = DB::table('provisioning_financial_invalidations')
            ->where('order_id', $order->orderId)
            ->first(['id', 'order_id', 'purchase_settlement_id', 'payment_intent_id', 'purchase_refund_id']);
        self::assertNotNull($firstInvalidation);
        self::assertSame($firstRefund->refundId, (int) $firstInvalidation->purchase_refund_id);

        $secondRefund = $this->recordRefund($settlement, 'second', $secondAmount, 6);
        self::assertSame(PaymentIntentState::Refunded, $secondRefund->state);
        self::assertSame($totalAmount, $secondRefund->cumulativeRefunded->amount());

        $finalInvalidation = DB::table('provisioning_financial_invalidations')
            ->where('order_id', $order->orderId)
            ->first(['id', 'purchase_refund_id']);
        self::assertNotNull($finalInvalidation);
        self::assertSame((int) $firstInvalidation->id, (int) $finalInvalidation->id);
        self::assertSame($firstRefund->refundId, (int) $finalInvalidation->purchase_refund_id);
        self::assertSame(1, DB::table('provisioning_financial_invalidations')->where('order_id', $order->orderId)->count());
        self::assertSame(
            $secondRefund->refundId,
            (int) DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('latest_purchase_refund_id'),
        );

        try {
            $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
                $order->orderPublicId,
                $this->purchaseOrderCorrelation('queue-after-repeated-refund'),
            );
            self::fail('A financially invalidated Order must never regain initial provisioning authority.');
        } catch (DomainException) {
            // Expected: current payment authority is no longer captured.
        }

        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'provisioning.initial.requested')->count());
    }

    private function recordRefund(
        PurchaseSettlementReceipt $settlement,
        string $suffix,
        int $amountIrr,
        int $minutesAfterSettlement,
    ): PurchaseRefundReceipt {
        $occurredAt = $this->purchaseOrderClock->value->modify('+'.$minutesAfterSettlement.' minutes');

        return $this->app->make(PurchaseRefundService::class)->record(
            'provisioning-repeated-refund-'.$suffix,
            $settlement->settlementPublicId,
            $settlement->providerCode,
            new VerifiedPaymentEvent(
                'evt-provisioning-repeated-refund-'.$suffix,
                hash('sha256', 'provisioning-repeated-refund-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    'refund-provisioning-repeated-'.$suffix,
                    'evt-provisioning-repeated-refund-'.$suffix,
                    Money::irr($amountIrr),
                    $occurredAt,
                    $occurredAt,
                    hash('sha256', 'provisioning-repeated-refund-evidence:'.$suffix),
                    ['provider_reference' => 'refund-provisioning-repeated-'.$suffix],
                ),
            ),
            $this->purchaseOrderCorrelation('refund-repeated-'.$suffix),
        );
    }
}
