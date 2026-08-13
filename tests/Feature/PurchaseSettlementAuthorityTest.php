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
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PurchaseSettlementClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-002 PAY-003 PAY-004 PAY-005 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchaseSettlementAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchaseSettlementClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new PurchaseSettlementClock(new DateTimeImmutable('2026-08-13T02:30:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_authoritative_provider_evidence_captures_purchase_exactly_once_without_wallet_credit(): void
    {
        $purchase = $this->purchaseIntent('capture', 'purchase_gateway');
        $this->submit($purchase->intentPublicId);
        $event = $this->event('evt-purchase-1', 'txn-purchase-1', $purchase->amount->amount(), true);
        $service = $this->app->make(PurchaseSettlementService::class);

        $settlement = $service->capture(
            $purchase->intentPublicId,
            'purchase_gateway',
            $event,
            $this->correlation('capture'),
        );

        self::assertFalse($settlement->replayed);
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->value('state'));
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('payment_provider_transactions')->count());
        self::assertSame(1, DB::table('payment_attempts')->where('payment_intent_id', $this->intentId($purchase->intentPublicId))->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payment.purchase.captured')->count());
        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
        self::assertSame($purchase->sourceQuotePublicId, $settlement->sourceQuotePublicId);

        $replay = $service->capture(
            $purchase->intentPublicId,
            'purchase_gateway',
            $event,
            $this->correlation('capture-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($settlement->settlementId, $replay->settlementId);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('payment_provider_transactions')->count());
    }

    public function test_non_authoritative_or_amount_mismatched_evidence_cannot_capture_purchase(): void
    {
        $purchase = $this->purchaseIntent('reject', 'secure_gateway');
        $this->submit($purchase->intentPublicId);
        $service = $this->app->make(PurchaseSettlementService::class);

        $this->assertDomainMessage(
            'Non-authoritative payment evidence cannot capture a purchase.',
            fn (): mixed => $service->capture(
                $purchase->intentPublicId,
                'secure_gateway',
                $this->event('evt-purchase-nonauth', 'txn-purchase-nonauth', $purchase->amount->amount(), false),
                $this->correlation('nonauth'),
            ),
        );
        $this->assertRuntimeMessage(
            'Authoritative provider evidence amount does not match the payment intent.',
            fn (): mixed => $service->capture(
                $purchase->intentPublicId,
                'secure_gateway',
                $this->event('evt-purchase-wrong-amount', 'txn-purchase-wrong-amount', $purchase->amount->amount() + 1, true),
                $this->correlation('wrong-amount'),
            ),
        );

        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame('submitted', DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->value('state'));
    }

    public function test_provider_transaction_cannot_settle_two_purchase_intents(): void
    {
        $first = $this->purchaseIntent('shared-first', 'shared_gateway');
        $second = $this->purchaseIntent('shared-second', 'shared_gateway');
        $this->submit($first->intentPublicId);
        $this->submit($second->intentPublicId);
        self::assertSame($first->amount->amount(), $second->amount->amount());

        $service = $this->app->make(PurchaseSettlementService::class);
        $service->capture(
            $first->intentPublicId,
            'shared_gateway',
            $this->event('evt-shared-first', 'txn-shared-purchase', $first->amount->amount(), true),
            $this->correlation('shared-first'),
        );

        $this->assertRuntimeMessage(
            'Payment provider transaction conflicts with an accepted transaction.',
            fn (): mixed => $service->capture(
                $second->intentPublicId,
                'shared_gateway',
                $this->event('evt-shared-second', 'txn-shared-purchase', $second->amount->amount(), true),
                $this->correlation('shared-second'),
            ),
        );

        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame('submitted', DB::table('payment_intents')->where('public_id', $second->intentPublicId)->value('state'));
    }

    public function test_database_requires_settlement_before_direct_purchase_capture_and_makes_settlement_immutable(): void
    {
        $purchase = $this->purchaseIntent('database-guard', 'guard_gateway');
        $this->submit($purchase->intentPublicId);
        DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->update([
            'state' => 'verifying',
            'updated_at' => $this->timestamp(),
        ]);

        $this->assertQueryRejected(fn (): int => DB::table('payment_intents')
            ->where('public_id', $purchase->intentPublicId)
            ->update([
                'state' => 'captured',
                'captured_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]));
        self::assertSame('verifying', DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->value('state'));

        $settlement = $this->app->make(PurchaseSettlementService::class)->capture(
            $purchase->intentPublicId,
            'guard_gateway',
            $this->event('evt-guard', 'txn-guard', $purchase->amount->amount(), true),
            $this->correlation('guard-capture'),
        );

        $this->assertQueryRejected(static fn (): int => DB::table('purchase_settlements')
            ->where('id', $settlement->settlementId)
            ->update(['amount_irr' => $settlement->amount->amount() + 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('purchase_settlements')
            ->where('id', $settlement->settlementId)
            ->delete());
    }

    private function purchaseIntent(string $suffix, string $methodCode): object
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $quote = $this->quoteFor($userId, $suffix);
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, $methodCode, $suffix);
        $decision = $eligibility->evaluate('eligibility.settlement.'.$suffix, $userId, $quote->quotePublicId);

        return $this->app->make(PurchasePaymentIntentService::class)->create(
            'purchase.settlement.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            $this->correlation('intent-'.$suffix),
        );
    }

    private function configureHealthyMethod(
        PaymentMethodEligibilityService $service,
        int $administratorId,
        string $methodCode,
        string $suffix,
    ): void {
        $service->configureMethod(
            'settlement.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Purchase settlement test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $service->recordHealth(
            'settlement.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy purchase settlement test observation.',
            $this->correlation('health-'.$suffix),
        );
    }

    private function quoteFor(int $userId, string $suffix): object
    {
        $offering = $this->quoteOffering();

        return $this->app->make(QuoteService::class)->create(
            'purchase.settlement.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
    }

    private function submit(string $intentPublicId): void
    {
        DB::table('payment_intents')->where('public_id', $intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->timestamp(),
        ]);
    }

    private function intentId(string $intentPublicId): int
    {
        return (int) DB::table('payment_intents')->where('public_id', $intentPublicId)->value('id');
    }

    private function event(string $eventId, string $transactionId, int $amountIrr, bool $authoritative): VerifiedPaymentEvent
    {
        return new VerifiedPaymentEvent(
            $eventId,
            hash('sha256', 'provider-event:'.$eventId),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                $authoritative ? PaymentEvidenceAuthority::Authoritative : PaymentEvidenceAuthority::NonAuthoritative,
                PaymentTransactionStatus::Settled,
                $transactionId,
                $eventId,
                Money::irr($amountIrr),
                $this->clock->value,
                $this->clock->value,
                hash('sha256', 'provider-evidence:'.$transactionId.':'.$amountIrr),
                ['provider_reference' => $transactionId],
            ),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-settlement:'.$suffix);
    }

    private function timestamp(): string
    {
        return $this->clock->value->format('Y-m-d H:i:s.u');
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (\DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
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
