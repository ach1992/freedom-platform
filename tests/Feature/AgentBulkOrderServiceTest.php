<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Application\AgentBulkOrderService;
use App\Modules\Orders\Application\AgentPurchaseCountService;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement AGT-003 AGT-004 BUY-001 BUY-002 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class AgentBulkOrderServiceTest extends TestCase
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

    public function test_agent_purchase_count_uses_authoritative_purchase_settlements_and_is_self_only(): void
    {
        [$agent, $offering] = $this->agentAuthority('purchase-count');
        $counts = $this->app->make(AgentPurchaseCountService::class);

        self::assertSame(0, $counts->forSelf($agent, $agent));

        $first = $this->agentSettlement('purchase-count-first', $agent, $offering['id']);
        self::assertSame(1, $counts->forSelf($agent, $agent));
        $this->agentSettlement('purchase-count-second', $agent, $offering['id']);
        self::assertSame(2, $counts->forSelf($agent, $agent));

        $customerSettlement = $this->createPurchaseOrderSettlement('purchase-count-customer');
        self::assertSame(2, $counts->forSelf($agent, $agent));

        $refundedAt = $this->purchaseOrderClock->value->modify('+5 minutes');
        $refundEventId = 'evt-agent-count-refund';
        $refund = $this->app->make(PurchaseRefundService::class)->record(
            'agent.purchase.count.refund.000001',
            $first->settlementPublicId,
            $first->providerCode,
            new VerifiedPaymentEvent(
                $refundEventId,
                hash('sha256', 'agent-purchase-count-refund-event'),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    'refund-agent-purchase-count-first',
                    $refundEventId,
                    Money::irr($first->amount->amount()),
                    $refundedAt,
                    $refundedAt,
                    hash('sha256', 'agent-purchase-count-refund-evidence'),
                    ['provider_reference' => 'refund-agent-purchase-count-first'],
                ),
            ),
            $this->purchaseOrderCorrelation('agent-count-refund'),
        );
        self::assertSame('refunded', $refund->state->value);
        self::assertSame(2, $counts->forSelf($agent, $agent));

        DB::table('agent_profiles')->where('user_id', $agent)->update([
            'status' => 'suspended',
            'suspended_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        self::assertSame(2, $counts->forSelf($agent, $agent));

        try {
            $counts->forSelf($customerSettlement->userId, $agent);
            self::fail('Cross-user Agent purchase count reads must fail closed.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Agent purchase count is self-only.', $exception->getMessage());
        }

        try {
            $counts->forSelf($customerSettlement->userId, $customerSettlement->userId);
            self::fail('Customer accounts must not read Agent purchase counts.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Agent purchase count requires a current Agent account.', $exception->getMessage());
        }
    }

    public function test_empty_bulk_request_fails_before_creating_any_authority(): void
    {
        [$agent] = $this->agentAuthority('bulk-empty');
        $service = $this->app->make(AgentBulkOrderService::class);

        try {
            $service->execute(
                'bulk-empty-parent-0001',
                $agent,
                [],
                $this->purchaseOrderCorrelation('bulk-empty-rejected'),
            );
            self::fail('Empty agent bulk Order requests must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('Agent bulk Order requires between 1 and 50 child purchases.', $exception->getMessage());
        }

        $this->assertNoBulkOrderAuthority();
    }

    public function test_over_limit_bulk_request_fails_before_creating_any_authority(): void
    {
        [$agent] = $this->agentAuthority('bulk-over-limit');
        $service = $this->app->make(AgentBulkOrderService::class);
        $items = [];
        for ($index = 1; $index <= 51; $index++) {
            $items[] = [
                'child_key' => sprintf('bulk-over-limit-child-%02d', $index),
                'purchase_settlement_public_id' => (string) Str::ulid(),
            ];
        }

        try {
            $service->execute(
                'bulk-over-limit-parent-01',
                $agent,
                $items,
                $this->purchaseOrderCorrelation('bulk-over-limit-rejected'),
            );
            self::fail('Oversized agent bulk Order requests must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('Agent bulk Order requires between 1 and 50 child purchases.', $exception->getMessage());
        }

        $this->assertNoBulkOrderAuthority();
    }

    public function test_database_rejects_out_of_range_bulk_parent_counts_independent_of_application_validation(): void
    {
        [$agent] = $this->agentAuthority('bulk-db-bounds');

        foreach ([0, 51] as $itemCount) {
            $this->assertQueryRejected(fn (): bool => DB::table('agent_bulk_orders')->insert([
                'public_id' => (string) Str::ulid(),
                'batch_key' => 'bulk-db-bound-'.str_pad((string) $itemCount, 2, '0', STR_PAD_LEFT),
                'request_payload_hash' => hash('sha256', 'bulk-db-bound-'.$itemCount),
                'user_id' => $agent,
                'item_count' => $itemCount,
                'creation_correlation_id' => $this->purchaseOrderCorrelation('bulk-db-bound-'.$itemCount),
                'created_at' => $this->purchaseOrderTimestamp(),
            ]));
            self::assertSame(0, DB::table('agent_bulk_orders')->count());
        }

        $this->assertNoBulkOrderAuthority();
    }

    public function test_bulk_success_replays_without_duplicate_order_or_payment_authority(): void
    {
        [$agent, $offering] = $this->agentAuthority('bulk-success');
        $first = $this->agentSettlement('bulk-success-a', $agent, $offering['id']);
        $second = $this->agentSettlement('bulk-success-b', $agent, $offering['id']);
        $service = $this->app->make(AgentBulkOrderService::class);
        $items = [
            ['child_key' => 'bulk-success-child-a', 'purchase_settlement_public_id' => $first->settlementPublicId],
            ['child_key' => 'bulk-success-child-b', 'purchase_settlement_public_id' => $second->settlementPublicId],
        ];

        $created = $service->execute('bulk-success-parent-0001', $agent, $items, $this->purchaseOrderCorrelation('bulk-success-create'));
        self::assertFalse($created->replayed);
        self::assertSame(2, $created->succeededCount);
        self::assertSame(0, $created->failedCount);
        self::assertSame(1, DB::table('agent_bulk_orders')->count());
        self::assertSame(2, DB::table('agent_bulk_order_items')->where('state', 'succeeded')->count());
        self::assertSame(2, DB::table('orders')->count());
        self::assertSame(2, DB::table('order_items')->count());
        self::assertSame(2, DB::table('payment_intents')->count());
        self::assertSame(2, DB::table('purchase_settlements')->count());

        $replay = $service->execute('bulk-success-parent-0001', $agent, $items, $this->purchaseOrderCorrelation('bulk-success-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($created->bulkOrderId, $replay->bulkOrderId);
        self::assertSame(2, $replay->succeededCount);
        self::assertSame(0, $replay->failedCount);
        self::assertSame(2, DB::table('orders')->count());
        self::assertSame(2, DB::table('order_items')->count());
        self::assertSame(2, DB::table('payment_intents')->count());
        self::assertSame(2, DB::table('purchase_settlements')->count());
        self::assertSame([1, 1], DB::table('agent_bulk_order_items')->orderBy('line_number')->pluck('attempt_count')->map(static fn ($value): int => (int) $value)->all());
    }

    public function test_failed_child_retry_never_recreates_successful_child_or_second_debit(): void
    {
        [$agent, $offering] = $this->agentAuthority('bulk-partial');
        $successfulSettlement = $this->agentSettlement('bulk-partial-success', $agent, $offering['id']);
        $firstConflictSettlement = $this->agentSettlement('bulk-partial-conflict', $agent, $offering['id']);
        $secondConflictSettlement = $this->secondSettlementForSameQuote('bulk-partial-conflict-second', $firstConflictSettlement);

        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $firstConflictSettlement->settlementPublicId,
            $this->purchaseOrderCorrelation('bulk-partial-existing-order'),
        );
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(3, DB::table('purchase_settlements')->count());

        $service = $this->app->make(AgentBulkOrderService::class);
        $items = [
            ['child_key' => 'bulk-partial-child-success', 'purchase_settlement_public_id' => $successfulSettlement->settlementPublicId],
            ['child_key' => 'bulk-partial-child-conflict', 'purchase_settlement_public_id' => $secondConflictSettlement->settlementPublicId],
        ];
        $firstRun = $service->execute('bulk-partial-parent-0001', $agent, $items, $this->purchaseOrderCorrelation('bulk-partial-first'));

        self::assertSame(1, $firstRun->succeededCount);
        self::assertSame(1, $firstRun->failedCount);
        self::assertSame('authority_rejected', $firstRun->items[1]['last_error_code']);
        self::assertSame(2, DB::table('orders')->count());
        self::assertSame(3, DB::table('payment_intents')->count());
        self::assertSame(3, DB::table('purchase_settlements')->count());

        $successfulOrderId = (int) DB::table('agent_bulk_order_items')
            ->where('child_key', 'bulk-partial-child-success')
            ->value('order_id');
        $replay = $service->execute('bulk-partial-parent-0001', $agent, $items, $this->purchaseOrderCorrelation('bulk-partial-replay'));

        self::assertTrue($replay->replayed);
        self::assertSame(1, $replay->succeededCount);
        self::assertSame(1, $replay->failedCount);
        self::assertSame($successfulOrderId, (int) DB::table('agent_bulk_order_items')->where('child_key', 'bulk-partial-child-success')->value('order_id'));
        self::assertSame(1, (int) DB::table('agent_bulk_order_items')->where('child_key', 'bulk-partial-child-success')->value('attempt_count'));
        self::assertSame(2, (int) DB::table('agent_bulk_order_items')->where('child_key', 'bulk-partial-child-conflict')->value('attempt_count'));
        self::assertSame(2, DB::table('orders')->count());
        self::assertSame(3, DB::table('payment_intents')->count());
        self::assertSame(3, DB::table('purchase_settlements')->count());
    }

    public function test_customer_settlement_and_direct_child_result_forgery_fail_closed(): void
    {
        [$agent] = $this->agentAuthority('bulk-auth');
        $customerSettlement = $this->createPurchaseOrderSettlement('bulk-customer-rejected');
        $service = $this->app->make(AgentBulkOrderService::class);

        try {
            $service->execute(
                'bulk-auth-parent-000001',
                $agent,
                [['child_key' => 'bulk-auth-customer-child', 'purchase_settlement_public_id' => $customerSettlement->settlementPublicId]],
                $this->purchaseOrderCorrelation('bulk-auth-rejected'),
            );
            self::fail('Customer purchase authority must not enter an agent bulk Order.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('agent_bulk_orders')->count());
            self::assertSame(0, DB::table('agent_bulk_order_items')->count());
        }

        [$validAgent, $offering] = $this->agentAuthority('bulk-direct-db');
        $settlement = $this->agentSettlement('bulk-direct-db', $validAgent, $offering['id']);
        $directChildReference = 'bulk-direct-child-0001';
        $receipt = $service->execute(
            'bulk-direct-parent-0001',
            $validAgent,
            [['child_key' => $directChildReference, 'purchase_settlement_public_id' => $settlement->settlementPublicId]],
            $this->purchaseOrderCorrelation('bulk-direct-create'),
        );
        self::assertSame(1, $receipt->succeededCount);

        $this->assertQueryRejected(fn (): int => DB::table('agent_bulk_order_items')
            ->where('agent_bulk_order_id', $receipt->bulkOrderId)
            ->update(['attempt_count' => 2, 'updated_at' => $this->purchaseOrderTimestamp()]));
    }

    public function test_batch_key_conflict_does_not_change_existing_parent_or_children(): void
    {
        [$agent, $offering] = $this->agentAuthority('bulk-conflict');
        $first = $this->agentSettlement('bulk-conflict-a', $agent, $offering['id']);
        $second = $this->agentSettlement('bulk-conflict-b', $agent, $offering['id']);
        $service = $this->app->make(AgentBulkOrderService::class);
        $service->execute(
            'bulk-conflict-parent-01',
            $agent,
            [['child_key' => 'bulk-conflict-child-a', 'purchase_settlement_public_id' => $first->settlementPublicId]],
            $this->purchaseOrderCorrelation('bulk-conflict-first'),
        );

        try {
            $service->execute(
                'bulk-conflict-parent-01',
                $agent,
                [['child_key' => 'bulk-conflict-child-b', 'purchase_settlement_public_id' => $second->settlementPublicId]],
                $this->purchaseOrderCorrelation('bulk-conflict-second'),
            );
            self::fail('A batch key replay with a different child request must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Agent bulk Order batch key conflicts with another request.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('agent_bulk_orders')->count());
        self::assertSame(1, DB::table('agent_bulk_order_items')->count());
        self::assertSame(1, DB::table('orders')->count());
    }

    /** @return array{0:int,1:array{id:int,product_id:int,server_id:int,owner_id:int,dependencies:array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>},service:mixed,code:string}} */
    private function agentAuthority(string $suffix): array
    {
        $profileCode = 'bulk-'.substr(hash('sha256', $suffix), 0, 16);
        $owner = $this->ownerAdministrator();
        $pricing = $this->app->make(AgentPricingService::class);
        $this->activePricingProfile($pricing, $owner, $profileCode, true);
        $agent = $this->agentSubject($profileCode);

        return [$agent, $this->quoteOffering()];
    }

    private function agentSettlement(string $suffix, int $agentUserId, int $offeringId): PurchaseSettlementReceipt
    {
        $methodCode = 'bulk_gateway_'.substr(hash('sha256', $suffix), 0, 16);
        $quote = $this->app->make(QuoteService::class)->create(
            'agent.bulk.quote.'.substr(hash('sha256', $suffix), 0, 32),
            $agentUserId,
            $offeringId,
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+30 minutes'),
            ),
            $this->purchaseOrderCorrelation('bulk-quote-'.$suffix),
            new QuoteAgentPricingContext($agentUserId, AgentPricingAction::Purchase),
        );

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'agent.bulk.method.'.substr(hash('sha256', $suffix), 0, 32),
            $this->ownerAdministrator(),
            $methodCode,
            true,
            false,
            1,
            'Agent bulk Order test method.',
            $this->purchaseOrderCorrelation('bulk-method-'.$suffix),
        );
        $eligibility->recordHealth(
            'agent.bulk.health.'.substr(hash('sha256', $suffix), 0, 32),
            $this->ownerAdministrator(),
            $methodCode,
            true,
            $this->purchaseOrderClock->value->modify('+10 minutes'),
            'Healthy agent bulk Order test method.',
            $this->purchaseOrderCorrelation('bulk-health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'agent.bulk.eligibility.'.substr(hash('sha256', $suffix), 0, 32),
            $agentUserId,
            $quote->quotePublicId,
        );
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'agent.bulk.intent.'.substr(hash('sha256', $suffix), 0, 32),
            $agentUserId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            $this->purchaseOrderCorrelation('bulk-intent-'.$suffix),
        );
        DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        return $this->app->make(PurchaseSettlementService::class)->capture(
            $intent->intentPublicId,
            $methodCode,
            $this->settlementEvent($suffix, $intent->amount->amount()),
            $this->purchaseOrderCorrelation('bulk-settlement-'.$suffix),
        );
    }

    private function secondSettlementForSameQuote(string $suffix, PurchaseSettlementReceipt $first): PurchaseSettlementReceipt
    {
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $decision = $eligibility->evaluate(
            'agent.bulk.eligibility.'.substr(hash('sha256', $suffix), 0, 32),
            $first->userId,
            $first->sourceQuotePublicId,
        );
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'agent.bulk.intent.'.substr(hash('sha256', $suffix), 0, 32),
            $first->userId,
            $first->sourceQuotePublicId,
            $decision->publicId,
            $first->providerCode,
            $this->purchaseOrderCorrelation('bulk-intent-'.$suffix),
        );
        DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        return $this->app->make(PurchaseSettlementService::class)->capture(
            $intent->intentPublicId,
            $first->providerCode,
            $this->settlementEvent($suffix, $intent->amount->amount()),
            $this->purchaseOrderCorrelation('bulk-settlement-'.$suffix),
        );
    }

    private function settlementEvent(string $suffix, int $amountIrr): VerifiedPaymentEvent
    {
        $eventId = 'evt-agent-bulk-'.substr(hash('sha256', $suffix), 0, 16);
        $transactionId = 'txn-agent-bulk-'.substr(hash('sha256', $suffix), 0, 16);

        return new VerifiedPaymentEvent(
            $eventId,
            hash('sha256', 'agent-bulk-provider-event:'.$suffix),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $transactionId,
                $eventId,
                Money::irr($amountIrr),
                $this->purchaseOrderClock->value,
                $this->purchaseOrderClock->value,
                hash('sha256', 'agent-bulk-provider-evidence:'.$suffix),
                ['provider_reference' => $transactionId],
            ),
        );
    }

    private function assertNoBulkOrderAuthority(): void
    {
        self::assertSame(0, DB::table('agent_bulk_orders')->count());
        self::assertSame(0, DB::table('agent_bulk_order_items')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('order_items')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected MariaDB authority rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
