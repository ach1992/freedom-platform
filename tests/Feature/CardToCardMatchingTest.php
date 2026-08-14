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
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\CardToCardMatchingService;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentReceipt;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\CardToCard\Application\CardToCardSettlementService;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
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
use Tests\TestCase;

final class MatchingFixedCardToCardAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        return $minimumIrr;
    }
}

final class CardToCardMatchingClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement C2C-001 C2C-002 C2C-003 C2C-004 C2C-005 PAY-002 PAY-003 WAL-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class CardToCardMatchingTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private CardToCardMatchingClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new CardToCardMatchingClock(new DateTimeImmutable('2026-08-14T10:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(CardToCardAdjustmentGenerator::class, new MatchingFixedCardToCardAdjustmentGenerator);
        config()->set('payments.card_to_card.lookup_key', str_repeat('m', 32));
        $this->configureMethod();
        $this->app->make(CardToCardDestinationService::class)->register(
            'matching-primary',
            '4242424242424242',
            'Matching Account',
            true,
            1000,
            9990,
            5,
            60,
            null,
            10,
            'fake',
            'Matching test destination.',
            $this->correlation('destination'),
        );
    }

    public function test_exact_settled_transaction_automatically_matches_one_on_time_reservation(): void
    {
        $payment = $this->payment('automatic');
        $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->observation('auto', $payment->payableAmountIrr, $this->clock->value->modify('+2 minutes')),
            'fake',
            $this->correlation('bank-auto'),
        );

        $match = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('match-auto'));

        self::assertSame('matched', $match->outcome);
        self::assertSame($payment->reservationId, $match->reservationId);
        self::assertNotNull($match->matchId);
        self::assertNull($match->reviewId);
        self::assertSame(1, DB::table('c2c_transaction_matches')->count());
        self::assertSame(0, DB::table('c2c_match_reviews')->count());

        $replay = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('match-auto-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($match->matchId, $replay->matchId);
    }

    public function test_exact_match_settles_payable_amount_through_common_authority_and_replays_once(): void
    {
        $payment = $this->payment('settlement');
        $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->observation('settlement', $payment->payableAmountIrr, $this->clock->value->modify('+2 minutes')),
            'fake',
            $this->correlation('bank-settlement'),
        );
        $match = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('match-settlement'));
        self::assertNotNull($match->matchPublicId);

        $captured = $this->app->make(CardToCardSettlementService::class)->capture(
            $match->matchPublicId,
            $this->correlation('capture-settlement'),
        );

        self::assertFalse($captured->replayed);
        self::assertSame($payment->baseAmountIrr, $captured->baseAmountIrr);
        self::assertSame($payment->adjustmentAmountIrr, $captured->adjustmentAmountIrr);
        self::assertSame($payment->payableAmountIrr, $captured->payableAmountIrr);
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->value('state'));
        self::assertSame($payment->baseAmountIrr, (int) DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->value('amount_irr'));
        self::assertSame($payment->payableAmountIrr, (int) DB::table('purchase_settlements')->where('id', $captured->purchaseSettlementId)->value('amount_irr'));
        self::assertSame('captured', DB::table('c2c_transaction_matches')->where('id', $captured->matchId)->value('state'));
        self::assertSame($captured->purchaseSettlementId, (int) DB::table('c2c_transaction_matches')->where('id', $captured->matchId)->value('purchase_settlement_id'));
        self::assertNull(DB::table('c2c_amount_reservations')->where('id', $payment->reservationId)->value('active_lock'));
        self::assertSame('matched', DB::table('c2c_amount_reservations')->where('id', $payment->reservationId)->value('release_reason'));

        $commonProviderTransactionId = (string) DB::table('purchase_settlements')
            ->where('id', $captured->purchaseSettlementId)
            ->value('provider_transaction_id');
        self::assertSame(hash('sha256', "fake\0fake-tx-settlement"), $commonProviderTransactionId);
        self::assertNotSame('fake-tx-settlement', $commonProviderTransactionId);

        $replay = $this->app->make(CardToCardSettlementService::class)->capture(
            $match->matchPublicId,
            $this->correlation('capture-settlement-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($captured->purchaseSettlementId, $replay->purchaseSettlementId);
        self::assertSame(1, DB::table('purchase_settlements')->where('payment_intent_id', DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->value('id'))->count());
        self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_code', 'card_to_card')->count());
    }

    public function test_c2c_refund_cap_excludes_non_refundable_exact_adjustment_in_application_and_database(): void
    {
        $payment = $this->payment('refund-cap');
        $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->observation('refund-cap', $payment->payableAmountIrr, $this->clock->value->modify('+2 minutes')),
            'fake',
            $this->correlation('bank-refund-cap'),
        );
        $match = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('match-refund-cap'));
        self::assertNotNull($match->matchPublicId);
        $captured = $this->app->make(CardToCardSettlementService::class)->capture(
            $match->matchPublicId,
            $this->correlation('capture-refund-cap'),
        );

        $this->clock->value = $this->clock->value->modify('+5 minutes');
        $refunds = $this->app->make(PurchaseRefundService::class);
        try {
            $refunds->record(
                'c2c.refund.over-cap',
                $captured->purchaseSettlementPublicId,
                'card_to_card',
                $this->refundEvent('over-cap', $payment->payableAmountIrr, $this->clock->value),
                $this->correlation('refund-over-cap'),
            );
            self::fail('Expected C2C refund to reject the non-refundable exact adjustment.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('refundable captured amount', $exception->getMessage());
        }
        self::assertSame(0, DB::table('purchase_refunds')->count());

        $settlement = DB::table('purchase_settlements')->where('id', $captured->purchaseSettlementId)->first();
        self::assertNotNull($settlement);
        $providerEventId = (int) DB::table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $settlement->payment_intent_id,
            'provider_code' => 'card_to_card',
            'provider_event_id' => 'db-refund-event-over-cap',
            'event_payload_hash' => hash('sha256', 'db-refund-event-over-cap'),
            'provider_transaction_id' => 'db-refund-tx-over-cap',
            'evidence_payload_hash' => hash('sha256', 'db-refund-evidence-over-cap'),
            'evidence_authority' => 'authoritative',
            'transaction_status' => 'refunded',
            'amount_irr' => $payment->baseAmountIrr + 1,
            'currency' => 'IRR',
            'occurred_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
            'settled_at' => null,
            'safe_evidence' => '{}',
            'created_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
        ]);
        $this->assertQueryRejected(fn (): bool => DB::table('purchase_refunds')->insert([
            'public_id' => (string) Str::ulid(),
            'refund_key' => 'c2c.refund.db-over-cap',
            'payload_hash' => hash('sha256', 'c2c.refund.db-over-cap'),
            'purchase_settlement_id' => $settlement->id,
            'payment_intent_id' => $settlement->payment_intent_id,
            'provider_event_row_id' => $providerEventId,
            'user_id' => $settlement->user_id,
            'provider_code' => 'card_to_card',
            'provider_refund_id' => 'db-refund-tx-over-cap',
            'evidence_payload_hash' => hash('sha256', 'db-refund-evidence-over-cap'),
            'amount_irr' => $payment->baseAmountIrr + 1,
            'cumulative_refunded_irr' => $payment->baseAmountIrr + 1,
            'currency' => 'IRR',
            'resulting_payment_state' => 'refunded',
            'refunded_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
            'correlation_id' => $this->correlation('db-refund-over-cap'),
            'created_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
        ]));
        self::assertSame(0, DB::table('purchase_refunds')->count());

        $refund = $refunds->record(
            'c2c.refund.full-base',
            $captured->purchaseSettlementPublicId,
            'card_to_card',
            $this->refundEvent('full-base', $payment->baseAmountIrr, $this->clock->value->modify('+1 minute')),
            $this->correlation('refund-full-base'),
        );
        self::assertSame($payment->baseAmountIrr, $refund->amount->amount());
        self::assertSame($payment->baseAmountIrr, $refund->cumulativeRefunded->amount());
        self::assertSame(PaymentIntentState::Refunded, $refund->state);
        self::assertSame('refunded', DB::table('payment_intents')->where('public_id', $payment->paymentIntent->intentPublicId)->value('state'));
        self::assertSame($payment->baseAmountIrr, (int) DB::table('purchase_refunds')->sum('amount_irr'));
    }

    public function test_database_rejects_direct_match_capture_without_authoritative_purchase_settlement(): void
    {
        $payment = $this->payment('forged-capture');
        $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->observation('forged-capture', $payment->payableAmountIrr, $this->clock->value->modify('+1 minute')),
            'fake',
            $this->correlation('bank-forged-capture'),
        );
        $match = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('match-forged-capture'));
        self::assertNotNull($match->matchId);

        $this->assertQueryRejected(static fn (): int => DB::table('c2c_transaction_matches')
            ->where('id', $match->matchId)
            ->update([
                'state' => 'captured',
                'purchase_settlement_id' => null,
                'captured_at' => '2026-08-14 10:03:00.000000',
            ]));

        self::assertSame('matched', DB::table('c2c_transaction_matches')->where('id', $match->matchId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    public function test_late_settled_transaction_requires_review_and_manual_acceptance_is_auditable(): void
    {
        $payment = $this->payment('late');
        $this->clock->value = $this->clock->value->modify('+10 minutes');
        $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->observation('late', $payment->payableAmountIrr, $this->clock->value),
            'fake',
            $this->correlation('bank-late'),
        );
        $matching = $this->app->make(CardToCardMatchingService::class);
        $review = $matching->match($bank->publicId, $this->correlation('match-late'));

        self::assertStringStartsWith('review_pending:late', $review->outcome);
        self::assertNotNull($review->reviewPublicId);
        self::assertSame(1, $review->candidateCount);
        self::assertSame(0, DB::table('c2c_transaction_matches')->count());

        $accepted = $matching->acceptReview(
            $review->reviewPublicId,
            $payment->reservationPublicId,
            $this->ownerAdministrator(),
            'Bank evidence confirms this late payment.',
            $this->correlation('accept-late'),
        );
        self::assertSame('matched', $accepted->outcome);
        self::assertSame($payment->reservationId, $accepted->reservationId);
        self::assertSame('accepted', DB::table('c2c_match_reviews')->where('public_id', $review->reviewPublicId)->value('state'));
        self::assertSame('manual', DB::table('c2c_transaction_matches')->where('id', $accepted->matchId)->value('match_mode'));
    }

    public function test_wrong_amount_creates_review_and_never_guesses_a_reservation(): void
    {
        $payment = $this->payment('wrong-amount');
        $bank = $this->app->make(CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->observation('wrong', $payment->payableAmountIrr + 1, $this->clock->value->modify('+1 minute')),
            'fake',
            $this->correlation('bank-wrong'),
        );

        $review = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('match-wrong'));

        self::assertSame('review_pending:no_candidate', $review->outcome);
        self::assertSame(0, $review->candidateCount);
        self::assertSame(0, DB::table('c2c_transaction_matches')->count());
        self::assertSame(1, DB::table('c2c_match_reviews')->count());
    }

    private function payment(string $suffix): CardToCardPaymentReceipt
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'c2c.match.quote.'.$suffix,
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'c2c.match.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
        );

        return $this->app->make(CardToCardPaymentService::class)->create(
            'c2c.match.intent.'.$suffix,
            $user,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('payment-'.$suffix),
        );
    }

    private function observation(string $suffix, int $amountIrr, DateTimeImmutable $occurredAt): BankTransactionObservation
    {
        return new BankTransactionObservation(
            'fake-tx-'.$suffix,
            'fake-event-'.$suffix,
            '4242424242424242',
            $amountIrr,
            'settled',
            $occurredAt,
            null,
            null,
            'reference-'.$suffix,
            hash('sha256', 'fake-bank-evidence:'.$suffix),
        );
    }

    private function refundEvent(string $suffix, int $amountIrr, DateTimeImmutable $occurredAt): VerifiedPaymentEvent
    {
        $eventId = 'refund-event-'.$suffix;
        $payloadHash = hash('sha256', 'c2c-refund-evidence:'.$suffix);

        return new VerifiedPaymentEvent(
            $eventId,
            $payloadHash,
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Refunded,
                'refund-transaction-'.$suffix,
                $eventId,
                Money::irr($amountIrr),
                $occurredAt,
                null,
                $payloadHash,
                ['source' => 'manual_external'],
            ),
        );
    }

    private function configureMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'c2c.match.method',
            $administratorId,
            'card_to_card',
            true,
            false,
            1,
            'C2C matching test configuration.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'c2c.match.health',
            $administratorId,
            'card_to_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy C2C matching test observation.',
            $this->correlation('health'),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'c2c-match:'.$suffix);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected card-to-card database authority rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
