<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\NonPaidOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use App\Modules\Orders\Domain\OrderSourceType;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionContext;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PRO-002 ADM-002 CAT-006 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
final class NonPaidOrderLifecycleTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_benefit_code_materializes_and_queues_once_without_financial_facts(): void
    {
        $offering = $this->benefitOffering('non-paid-benefit-lifecycle');
        $userId = $this->benefitUser();
        $entitlementPublicId = $this->freeServiceEntitlement($userId, $offering, 'non-paid-benefit-lifecycle');
        $paymentIntentCount = DB::table('payment_intents')->count();
        $settlementCount = DB::table('purchase_settlements')->count();

        $authorization = $this->app->make(OrderSourceAuthorizationService::class)
            ->authorizeBenefitCode($entitlementPublicId, 'non-paid-benefit-source-01');
        $orders = $this->app->make(NonPaidOrderService::class);
        $created = $orders->materialize($authorization->publicId, 'non-paid-benefit-order-01');
        $orderReplay = $orders->materialize($authorization->publicId, 'non-paid-benefit-order-02');

        self::assertFalse($created->replayed);
        self::assertTrue($orderReplay->replayed);
        self::assertSame($created->orderId, $orderReplay->orderId);
        self::assertSame($created->orderItemPublicId, $orderReplay->orderItemPublicId);
        self::assertSame(OrderSourceType::BenefitCode, $created->sourceType);
        self::assertSame(OrderState::Authorized, $created->state);
        self::assertSame(0, $created->stateVersion);
        self::assertSame(0, $created->commercialAmount->amount());

        $queue = $this->app->make(InitialProvisioningQueueService::class);
        $queued = $queue->queueInitial($created->orderPublicId, 'non-paid-benefit-queue-01');
        $queueReplay = $queue->queueInitial($created->orderPublicId, 'non-paid-benefit-queue-02');

        self::assertFalse($queued->replayed);
        self::assertTrue($queueReplay->replayed);
        self::assertSame($queued->serviceSubscriptionId, $queueReplay->serviceSubscriptionId);
        self::assertSame($queued->provisioningOperationId, $queueReplay->provisioningOperationId);
        self::assertSame($queued->outboxEventId, $queueReplay->outboxEventId);
        self::assertSame(OrderState::ProvisioningQueued, $queued->orderState);
        self::assertSame(1, $queued->orderStateVersion);
        self::assertSame(ProvisioningState::Queued, $queued->provisioningState);
        self::assertSame(1, $queued->provisioningStateVersion);

        $this->assertZeroCostAuthorityShape($created->orderId, $authorization->authorizationId, 'benefit_code');
        self::assertSame(1, DB::table('service_subscriptions')->where('order_id', $created->orderId)->count());
        self::assertSame(1, DB::table('provisioning_operations')->where('order_id', $created->orderId)->count());
        self::assertSame('pending', DB::table('outbox_messages')->where('id', $queued->outboxEventId)->value('dispatch_state'));
        self::assertSame($paymentIntentCount, DB::table('payment_intents')->count());
        self::assertSame($settlementCount, DB::table('purchase_settlements')->count());
    }

    public function test_administrator_grant_uses_same_zero_cost_order_and_queue_authority(): void
    {
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $offering = $this->benefitOffering('non-paid-admin-lifecycle');
        $paymentIntentCount = DB::table('payment_intents')->count();
        $settlementCount = DB::table('purchase_settlements')->count();

        $authorization = $this->app->make(OrderSourceAuthorizationService::class)
            ->authorizeAdministratorGrant(
                'non-paid-admin-grant-000001',
                $ownerId,
                $userId,
                $offering['id'],
                'manual_service_grant',
                'non-paid-admin-source-01',
            );
        $order = $this->app->make(NonPaidOrderService::class)
            ->materialize($authorization->publicId, 'non-paid-admin-order-01');
        $queued = $this->app->make(InitialProvisioningQueueService::class)
            ->queueInitial($order->orderPublicId, 'non-paid-admin-queue-01');

        self::assertSame(OrderSourceType::AdminGrant, $order->sourceType);
        self::assertSame(OrderState::ProvisioningQueued, $queued->orderState);
        self::assertSame(1, $queued->orderStateVersion);
        self::assertSame(ProvisioningState::Queued, $queued->provisioningState);
        $this->assertZeroCostAuthorityShape($order->orderId, $authorization->authorizationId, 'admin_grant');
        self::assertSame($paymentIntentCount, DB::table('payment_intents')->count());
        self::assertSame($settlementCount, DB::table('purchase_settlements')->count());
    }

    /** @param array{id:int,product_id:int,server_id:int} $offering */
    private function freeServiceEntitlement(int $userId, array $offering, string $suffix): string
    {
        $campaignCode = 'benefit.free.'.substr(hash('sha256', $suffix), 0, 12);
        $this->benefitCampaign(
            $campaignCode,
            BenefitCodeType::FreeService,
            $this->freeServiceDefinition($offering['id'], $offering['product_id'], $offering['server_id']),
            $suffix,
        );
        $issued = $this->benefitIssue($campaignCode, $suffix);
        $receipt = $this->app->make(BenefitCodeService::class)->redeem(
            new BenefitCodeRedemptionRequest(
                'free-non-paid-'.substr(hash('sha256', $suffix), 0, 32),
                (string) $issued->items[0]->fullCode,
                $userId,
                $offering['id'],
                null,
                'free-source-'.substr(hash('sha256', $suffix), 0, 32),
            ),
            new BenefitCodeRedemptionContext($userId),
        );
        self::assertNotNull($receipt->entitlementPublicId);

        return $receipt->entitlementPublicId;
    }

    private function assertZeroCostAuthorityShape(int $orderId, int $authorizationId, string $sourceType): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        self::assertNotNull($order);
        self::assertSame($sourceType, $order->source_type);
        self::assertSame($authorizationId, (int) $order->order_source_authorization_id);
        self::assertNull($order->purchase_settlement_id);
        self::assertNull($order->payment_intent_id);
        self::assertNull($order->source_quote_id);
        self::assertSame(0, (int) $order->total_amount_irr);
        self::assertNull($order->settled_amount_irr);
        self::assertNull($order->paid_at);
        self::assertSame('provisioning_queued', $order->state);
        self::assertSame(1, (int) $order->state_version);

        $item = DB::table('order_items')->where('order_id', $orderId)->first();
        self::assertNotNull($item);
        self::assertSame($authorizationId, (int) $item->order_source_authorization_id);
        self::assertNull($item->source_quote_id);
        self::assertSame('source', $item->override_source);
        self::assertSame($sourceType, $item->override_reference_code);
        self::assertSame(0, (int) $item->effective_price_irr);
        self::assertSame(0, (int) $item->final_price_irr);
    }
}
