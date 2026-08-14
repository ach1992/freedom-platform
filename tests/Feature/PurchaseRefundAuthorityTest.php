<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PurchaseRefundAuthorityClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-002 PAY-003 WAL-004 DAT-002 DAT-003 QUA-004 */
final class PurchaseRefundAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchaseRefundAuthorityClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new PurchaseRefundAuthorityClock(new DateTimeImmutable('2026-08-14T04:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_partial_then_full_refund_is_bound_to_one_settlement_and_replays_exactly_once(): void
    {
        [$settlement, $providerCode] = $this->capturePurchase('lifecycle');
        $total = $settlement->amount->amount();
        $partial = intdiv($total, 2);
        self::assertGreaterThan(0, $partial);
        self::assertLessThan($total, $partial);

        $service = $this->app->make(PurchaseRefundService::class);
        $partialEvent = $this->refundEvent('partial', $partial);
        $first = $service->record(
            'purchase.refund.lifecycle.000001',
            $settlement->settlementPublicId,
            $providerCode,
            $partialEvent,
            $this->correlation('refund-partial'),
        );

        self::assertFalse($first->replayed);
        self::assertSame(PaymentIntentState::PartiallyRefunded, $first->state);
        self::assertSame($partial, $first->amount->amount());
        self::assertSame($partial, $first->cumulativeRefunded->amount());
        self::assertSame(1, DB::table('purchase_refunds')->count());
        self::assertSame('partially_refunded', DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
        self::assertSame(
            $first->refundId,
            (int) DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('latest_purchase_refund_id'),
        );

        $replay = $service->record(
            'purchase.refund.lifecycle.000001',
            $settlement->settlementPublicId,
            $providerCode,
            $partialEvent,
            $this->correlation('refund-partial-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->refundId, $replay->refundId);
        self::assertSame(1, DB::table('purchase_refunds')->count());

        $remaining = $total - $partial;
        $full = $service->record(
            'purchase.refund.lifecycle.000002',
            $settlement->settlementPublicId,
            $providerCode,
            $this->refundEvent('full', $remaining),
            $this->correlation('refund-full'),
        );
        self::assertSame(PaymentIntentState::Refunded, $full->state);
        self::assertSame($total, $full->cumulativeRefunded->amount());
        self::assertSame(2, DB::table('purchase_refunds')->count());
        self::assertSame('refunded', DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('state'));
        self::assertSame($full->refundId, (int) DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('latest_purchase_refund_id'));
        $intentId = (int) DB::table('purchase_settlements')->where('id', $settlement->settlementId)->value('payment_intent_id');
        self::assertSame(
            ['refund_pending', 'partially_refunded', 'refund_pending', 'refunded'],
            DB::table('payment_intent_state_histories')
                ->where('payment_intent_id', $intentId)
                ->whereIn('to_state', ['refund_pending', 'partially_refunded', 'refunded'])
                ->orderBy('id')
                ->pluck('to_state')
                ->all(),
        );
    }

    public function test_refund_evidence_must_be_authoritative_and_cumulative_amount_is_bounded(): void
    {
        [$settlement, $providerCode] = $this->capturePurchase('bounds');
        $total = $settlement->amount->amount();
        $partial = intdiv($total, 2);
        $service = $this->app->make(PurchaseRefundService::class);

        $nonAuthoritative = new VerifiedPaymentEvent(
            'purchase-refund-event-non-authoritative',
            hash('sha256', 'purchase-refund-event-payload:non-authoritative'),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::NonAuthoritative,
                PaymentTransactionStatus::Refunded,
                'purchase-refund-transaction-non-authoritative',
                'purchase-refund-event-non-authoritative',
                Money::irr($partial),
                $this->clock->value->modify('+1 hour'),
                null,
                hash('sha256', 'purchase-refund-evidence:non-authoritative'),
                ['provider_reference' => 'purchase-refund-transaction-non-authoritative'],
            ),
        );
        $this->assertDomainMessage(
            'Only authoritative successful refund evidence can change purchase refund state.',
            fn (): mixed => $service->record(
                'purchase.refund.bounds.000000',
                $settlement->settlementPublicId,
                $providerCode,
                $nonAuthoritative,
                $this->correlation('refund-non-authoritative'),
            ),
        );

        $service->record(
            'purchase.refund.bounds.000001',
            $settlement->settlementPublicId,
            $providerCode,
            $this->refundEvent('bounds-partial', $partial),
            $this->correlation('refund-bounds-partial'),
        );

        $this->assertDomainMessage(
            'Purchase refund would exceed authoritative captured amount.',
            fn (): mixed => $service->record(
                'purchase.refund.bounds.000002',
                $settlement->settlementPublicId,
                $providerCode,
                $this->refundEvent('bounds-over', ($total - $partial) + 1),
                $this->correlation('refund-bounds-over'),
            ),
        );
        self::assertSame(1, DB::table('purchase_refunds')->count());
        self::assertSame($partial, (int) DB::table('purchase_refunds')->sum('amount_irr'));
    }

    public function test_changed_replay_and_provider_refund_reuse_fail_closed(): void
    {
        [$settlement, $providerCode] = $this->capturePurchase('conflict');
        $amount = intdiv($settlement->amount->amount(), 3);
        self::assertGreaterThan(0, $amount);
        $service = $this->app->make(PurchaseRefundService::class);
        $event = $this->refundEvent('conflict', $amount);

        $service->record(
            'purchase.refund.conflict.000001',
            $settlement->settlementPublicId,
            $providerCode,
            $event,
            $this->correlation('refund-conflict-first'),
        );

        $this->assertRuntimeMessage(
            'Purchase refund key conflict.',
            fn (): mixed => $service->record(
                'purchase.refund.conflict.000001',
                $settlement->settlementPublicId,
                $providerCode,
                $this->refundEvent('conflict-changed', $amount + 1),
                $this->correlation('refund-conflict-changed'),
            ),
        );

        $sameProviderRefund = new VerifiedPaymentEvent(
            'purchase-refund-event-conflict-second-key',
            hash('sha256', 'purchase-refund-event-payload:conflict-second-key'),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Refunded,
                $event->evidence->providerTransactionId,
                'purchase-refund-event-conflict-second-key',
                Money::irr($amount),
                $this->clock->value->modify('+2 hours'),
                null,
                hash('sha256', 'purchase-refund-evidence:conflict-second-key'),
                ['provider_reference' => $event->evidence->providerTransactionId],
            ),
        );
        $this->assertRuntimeMessage(
            'Authoritative provider refund is already bound to another purchase refund key.',
            fn (): mixed => $service->record(
                'purchase.refund.conflict.000002',
                $settlement->settlementPublicId,
                $providerCode,
                $sameProviderRefund,
                $this->correlation('refund-conflict-second-key'),
            ),
        );
        self::assertSame(1, DB::table('purchase_refunds')->count());
    }

    public function test_database_rejects_authorityless_transition_forged_binding_and_mutation(): void
    {
        [$settlement, $providerCode] = $this->capturePurchase('database');
        $intentId = (int) DB::table('purchase_settlements')->where('id', $settlement->settlementId)->value('payment_intent_id');

        $this->assertQueryRejected(static fn (): int => DB::table('payment_intents')
            ->where('id', $intentId)
            ->update(['state' => 'refund_pending', 'updated_at' => now('UTC')]));

        $captureEventId = (int) DB::table('payment_provider_events')
            ->where('payment_intent_id', $intentId)
            ->where('transaction_status', 'settled')
            ->value('id');
        $this->assertQueryRejected(fn (): int => DB::table('purchase_refunds')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'refund_key' => 'purchase.refund.database.forged',
            'payload_hash' => hash('sha256', 'forged-purchase-refund'),
            'purchase_settlement_id' => $settlement->settlementId,
            'payment_intent_id' => $intentId,
            'provider_event_row_id' => $captureEventId,
            'user_id' => $settlement->userId,
            'provider_code' => $providerCode,
            'provider_refund_id' => 'forged-refund-provider-id',
            'evidence_payload_hash' => hash('sha256', 'forged-refund-evidence'),
            'amount_irr' => 1,
            'cumulative_refunded_irr' => 1,
            'currency' => 'IRR',
            'resulting_payment_state' => 'partially_refunded',
            'refunded_at' => $this->clock->value->modify('+1 hour')->format('Y-m-d H:i:s.u'),
            'correlation_id' => $this->correlation('forged-refund'),
            'created_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
        ]));

        $valid = $this->app->make(PurchaseRefundService::class)->record(
            'purchase.refund.database.valid',
            $settlement->settlementPublicId,
            $providerCode,
            $this->refundEvent('database-valid', intdiv($settlement->amount->amount(), 2)),
            $this->correlation('database-valid'),
        );
        $this->assertQueryRejected(static fn (): int => DB::table('purchase_refunds')
            ->where('id', $valid->refundId)
            ->update(['amount_irr' => 1]));
    }

    /** @return array{0:PurchaseSettlementReceipt,1:string} */
    private function capturePurchase(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.refund.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $methodCode = 'purchase_refund_gateway_'.$suffix;
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'purchase.refund.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Purchase refund authority test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'purchase.refund.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy purchase refund authority test observation.',
            $this->correlation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate('purchase.refund.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        $purchase = $this->app->make(PurchasePaymentIntentService::class)->create(
            'purchase.refund.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            $this->correlation('intent-'.$suffix),
        );
        DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->timestamp(),
        ]);
        $captureEventId = 'purchase-refund-capture-event-'.$suffix;
        $settlement = $this->app->make(PurchaseSettlementService::class)->capture(
            $purchase->intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                $captureEventId,
                hash('sha256', 'purchase-refund-capture-event-payload:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'purchase-refund-capture-transaction-'.$suffix,
                    $captureEventId,
                    Money::irr($purchase->amount->amount()),
                    $this->clock->value,
                    $this->clock->value,
                    hash('sha256', 'purchase-refund-capture-evidence:'.$suffix),
                    ['provider_reference' => 'purchase-refund-capture-transaction-'.$suffix],
                ),
            ),
            $this->correlation('capture-'.$suffix),
        );

        return [$settlement, $methodCode];
    }

    private function refundEvent(string $suffix, int $amountIrr): VerifiedPaymentEvent
    {
        $eventId = 'purchase-refund-event-'.$suffix;

        return new VerifiedPaymentEvent(
            $eventId,
            hash('sha256', 'purchase-refund-event-payload:'.$suffix),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Refunded,
                'purchase-refund-transaction-'.$suffix,
                $eventId,
                Money::irr($amountIrr),
                $this->clock->value->modify('+1 hour'),
                null,
                hash('sha256', 'purchase-refund-evidence:'.$suffix),
                ['provider_reference' => 'purchase-refund-transaction-'.$suffix],
            ),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-refund:'.$suffix);
    }

    private function timestamp(): string
    {
        return $this->clock->value->format('Y-m-d H:i:s.u');
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected DomainException.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
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
