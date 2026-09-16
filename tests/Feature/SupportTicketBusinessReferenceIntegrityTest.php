<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement SUP-001 SEC-002 DAT-003 QUA-004 */
final class SupportTicketBusinessReferenceIntegrityTest extends TestCase
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
        $this->seed(SupportTicketCategorySeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_owned_order_payment_and_service_references_are_accepted_together(): void
    {
        $references = $this->referenceBundle('support-owned');
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $this->purchaseOrderClock);

        $ticket = $service->create(new SupportTicketCreateRequest(
            $references['user_id'],
            'technical_service',
            'Owned business references',
            'The ticket belongs to the same customer as every linked authority.',
            'support-reference:owned',
            orderId: $references['order_id'],
            paymentIntentId: $references['payment_intent_id'],
            serviceSubscriptionId: $references['service_subscription_id'],
        ));

        $stored = DB::table('support_tickets')->where('id', $ticket->id)->first([
            'requester_user_id', 'order_id', 'payment_intent_id', 'service_subscription_id',
        ]);
        self::assertNotNull($stored);
        self::assertSame($references['user_id'], (int) $stored->requester_user_id);
        self::assertSame($references['order_id'], (int) $stored->order_id);
        self::assertSame($references['payment_intent_id'], (int) $stored->payment_intent_id);
        self::assertSame($references['service_subscription_id'], (int) $stored->service_subscription_id);
    }

    public function test_cross_customer_business_references_are_rejected_for_every_reference_type(): void
    {
        $references = $this->referenceBundle('support-cross-owner');
        $otherCustomer = $this->unrelatedCustomer();
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $this->purchaseOrderClock);

        foreach ([
            'order' => ['orderId' => $references['order_id']],
            'payment' => ['paymentIntentId' => $references['payment_intent_id']],
            'service' => ['serviceSubscriptionId' => $references['service_subscription_id']],
        ] as $suffix => $reference) {
            try {
                $service->create(new SupportTicketCreateRequest(
                    $otherCustomer,
                    'other',
                    'Cross-owner '.$suffix,
                    'This reference must fail ownership validation.',
                    'support-reference:cross-'.$suffix,
                    orderId: $reference['orderId'] ?? null,
                    paymentIntentId: $reference['paymentIntentId'] ?? null,
                    serviceSubscriptionId: $reference['serviceSubscriptionId'] ?? null,
                ));
                self::fail('Cross-customer '.$suffix.' reference must be rejected by MariaDB authority.');
            } catch (QueryException) {
                self::assertFalse(DB::table('support_tickets')->where('requester_user_id', $otherCustomer)->exists());
            }
        }
    }

    public function test_nonexistent_business_references_are_rejected_for_every_reference_type(): void
    {
        $customer = $this->unrelatedCustomer();
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $this->purchaseOrderClock);
        $missing = 9_000_000_000;

        foreach ([
            'order' => ['orderId' => $missing],
            'payment' => ['paymentIntentId' => $missing],
            'service' => ['serviceSubscriptionId' => $missing],
        ] as $suffix => $reference) {
            try {
                $service->create(new SupportTicketCreateRequest(
                    $customer,
                    'other',
                    'Missing '.$suffix,
                    'This reference must fail referential integrity.',
                    'support-reference:missing-'.$suffix,
                    orderId: $reference['orderId'] ?? null,
                    paymentIntentId: $reference['paymentIntentId'] ?? null,
                    serviceSubscriptionId: $reference['serviceSubscriptionId'] ?? null,
                ));
                self::fail('Nonexistent '.$suffix.' reference must be rejected by MariaDB authority.');
            } catch (QueryException) {
                self::assertFalse(DB::table('support_tickets')->where('requester_user_id', $customer)->exists());
            }
        }
    }

    /** @return array{user_id:int,order_id:int,payment_intent_id:int,service_subscription_id:int} */
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
        $paymentIntentId = DB::table('payment_intents')
            ->where('public_id', $settlement->intentPublicId)
            ->value('id');
        self::assertNotNull($paymentIntentId);

        return [
            'user_id' => $settlement->userId,
            'order_id' => $order->orderId,
            'payment_intent_id' => (int) $paymentIntentId,
            'service_subscription_id' => $queued->serviceSubscriptionId,
        ];
    }

    private function unrelatedCustomer(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
