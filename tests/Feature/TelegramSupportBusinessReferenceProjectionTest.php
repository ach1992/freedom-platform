<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\Contracts\TelegramSupportOwnedOrderProjection;
use App\Modules\Telegram\Application\Contracts\TelegramSupportOwnedPaymentIntentProjection;
use App\Modules\Telegram\Application\Contracts\TelegramSupportOwnedServiceReferenceResolver;
use App\Modules\Telegram\Application\TelegramOwnedServiceListItem;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferenceListItem;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** @requirement SUP-001 SEC-002 DAT-003 QUA-001 QUA-004 */
final class TelegramSupportBusinessReferenceProjectionTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support business-reference projection verification requires MariaDB/MySQL.');
        }

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_owned_order_payment_and_service_projections_are_self_only_and_resolve_opaque_tokens(): void
    {
        $references = $this->referenceBundle('telegram-support-projection');
        $userId = $references['user_id'];
        $otherUserId = $this->quoteUser('customer');

        $orders = $this->app->make(TelegramSupportOwnedOrderProjection::class);
        $orderPage = $orders->pageForSelf($userId, $userId, 1, 6);
        $orderItem = $this->itemForPublicId($orderPage->items, $references['order_public_id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $orderItem->selectionToken);
        self::assertNotSame((string) $references['order_id'], $orderItem->selectionToken);
        self::assertSame(
            $references['order_id'],
            $orders->resolveForSelf($userId, $userId, $orderItem->selectionToken)->internalId,
        );

        $payments = $this->app->make(TelegramSupportOwnedPaymentIntentProjection::class);
        $paymentPage = $payments->pageForSelf($userId, $userId, 1, 6);
        $paymentItem = $this->itemForPublicId($paymentPage->items, $references['payment_public_id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $paymentItem->selectionToken);
        self::assertNotSame((string) $references['payment_intent_id'], $paymentItem->selectionToken);
        self::assertSame(
            $references['payment_intent_id'],
            $payments->resolveForSelf($userId, $userId, $paymentItem->selectionToken)->internalId,
        );

        $services = $this->app->make(TelegramOwnedServiceProjection::class);
        $servicePage = $services->pageForSelf($userId, $userId, 1, 6);
        $serviceItem = $this->serviceItemForPublicId($servicePage->items, $references['service_public_id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $serviceItem->selectionToken);
        self::assertNotSame((string) $references['service_subscription_id'], $serviceItem->selectionToken);
        self::assertSame(
            $references['service_subscription_id'],
            $this->app->make(TelegramSupportOwnedServiceReferenceResolver::class)
                ->resolveForSelf($userId, $userId, $serviceItem->selectionToken)
                ->internalId,
        );
        self::assertSame(1, $orders->pageForSelf($userId, $userId, 2, 6)->page);

        try {
            $orders->pageForSelf($userId, $userId, 1, 7);
            self::fail('Telegram Support Order projection must enforce its bounded page size.');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        foreach ([
            static fn () => $orders->pageForSelf($otherUserId, $userId, 1, 6),
            static fn () => $orders->resolveForSelf($otherUserId, $userId, $orderItem->selectionToken),
            static fn () => $payments->pageForSelf($otherUserId, $userId, 1, 6),
            static fn () => $payments->resolveForSelf($otherUserId, $userId, $paymentItem->selectionToken),
            fn () => $services->pageForSelf($otherUserId, $userId, 1, 6),
            fn () => $this->app->make(TelegramSupportOwnedServiceReferenceResolver::class)
                ->resolveForSelf($otherUserId, $userId, $serviceItem->selectionToken),
            static fn () => $orders->resolveForSelf($userId, $userId, str_repeat('a', 40)),
            static fn () => $payments->resolveForSelf($userId, $userId, str_repeat('b', 40)),
            fn () => $this->app->make(TelegramSupportOwnedServiceReferenceResolver::class)
                ->resolveForSelf($userId, $userId, str_repeat('c', 40)),
        ] as $operation) {
            try {
                $operation();
                self::fail('Cross-actor or forged Telegram Support business-reference access must fail closed.');
            } catch (AuthorizationException) {
                // Expected.
            }
        }
    }

    /** @param list<TelegramSupportBusinessReferenceListItem> $items */
    private function itemForPublicId(array $items, string $publicId): TelegramSupportBusinessReferenceListItem
    {
        foreach ($items as $item) {
            if (hash_equals($item->publicId, $publicId)) {
                return $item;
            }
        }

        self::fail('Expected Telegram Support business-reference projection item was not found.');
    }

    /** @param list<TelegramOwnedServiceListItem> $items */
    private function serviceItemForPublicId(array $items, string $publicId): TelegramOwnedServiceListItem
    {
        foreach ($items as $item) {
            if (hash_equals($item->publicId, $publicId)) {
                return $item;
            }
        }

        self::fail('Expected Telegram owned Service projection item was not found.');
    }

    /**
     * @return array{
     *     user_id:int,
     *     order_id:int,
     *     order_public_id:string,
     *     payment_intent_id:int,
     *     payment_public_id:string,
     *     service_subscription_id:int,
     *     service_public_id:string
     * }
     */
    private function referenceBundle(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
        $queued = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('queue-'.$suffix),
        );
        $paymentIntentId = DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('id');
        $servicePublicId = DB::table('service_subscriptions')->where('id', $queued->serviceSubscriptionId)->value('public_id');
        self::assertNotNull($paymentIntentId);
        self::assertIsString($servicePublicId);

        return [
            'user_id' => $settlement->userId,
            'order_id' => $order->orderId,
            'order_public_id' => $order->orderPublicId,
            'payment_intent_id' => (int) $paymentIntentId,
            'payment_public_id' => $settlement->intentPublicId,
            'service_subscription_id' => $queued->serviceSubscriptionId,
            'service_public_id' => $servicePublicId,
        ];
    }
}
