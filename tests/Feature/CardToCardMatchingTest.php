<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\CardToCardMatchingService;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

/** @requirement C2C-001 C2C-002 C2C-003 C2C-004 C2C-005 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
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
        $this->app->instance(CardToCardAdjustmentGenerator::class, new MatchingFixedCardToCardAdjustmentGenerator());
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

    private function payment(string $suffix): \App\Modules\Payments\CardToCard\Application\CardToCardPaymentReceipt
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
}
