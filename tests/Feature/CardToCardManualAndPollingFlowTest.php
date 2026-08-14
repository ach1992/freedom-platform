<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\CardToCardManualSubmissionService;
use App\Modules\Payments\CardToCard\Application\CardToCardMatchingService;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\CardToCard\Application\CardToCardProviderPollingService;
use App\Modules\Payments\CardToCard\Application\CardToCardReviewDecisionService;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionPage;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\CardToCard\Infrastructure\FakeBankTransactionVerificationProvider;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ManualPollingFixedC2cAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        return $minimumIrr;
    }
}

final class ManualPollingC2cClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement C2C-003 C2C-004 C2C-005 ACL-002 SEC-002 DAT-002 DAT-003 DAT-004 QUA-004 */
final class CardToCardManualAndPollingFlowTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private ManualPollingC2cClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new ManualPollingC2cClock(new DateTimeImmutable('2026-08-14T12:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(CardToCardAdjustmentGenerator::class, new ManualPollingFixedC2cAdjustmentGenerator());
        config()->set('payments.card_to_card.lookup_key', str_repeat('p', 32));
        $this->configureMethod();
        $this->app->make(CardToCardDestinationService::class)->register(
            'manual-polling-primary',
            '4242424242424242',
            'Manual Polling Account',
            true,
            1000,
            9990,
            5,
            60,
            null,
            10,
            'fake',
            'Manual/polling test destination.',
            $this->correlation('destination'),
        );
    }

    public function test_manual_submission_is_protected_evidence_and_authorized_late_review_captures_atomically(): void
    {
        $payment = $this->payment('manual');
        $paidAt = $this->clock->value->modify('+10 minutes');
        $submission = $this->app->make(CardToCardManualSubmissionService::class)->submit(
            'c2c.manual.submission.0001',
            $payment['user_id'],
            $payment['receipt']->reservationPublicId,
            $payment['receipt']->payableAmountIrr,
            $paidAt,
            hash('sha256', 'manual-receipt-evidence'),
            '6037991234567890',
            'Sender Name',
            'bank-reference-1',
            'private://telegram/receipt/opaque-1',
            $this->correlation('manual-submit'),
        );

        self::assertFalse($submission->replayed);
        self::assertSame('submitted', DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->value('state'));
        $storedSubmission = DB::table('c2c_manual_submissions')->where('id', $submission->submissionId)->first();
        self::assertNotNull($storedSubmission);
        self::assertSame(64, strlen((string) $storedSubmission->sender_card_lookup_hash));
        self::assertNotSame('6037991234567890', $storedSubmission->sender_card_lookup_hash);
        self::assertNotSame('Sender Name', $storedSubmission->encrypted_sender_name);
        self::assertSame('private://telegram/receipt/opaque-1', $storedSubmission->private_receipt_reference);
        self::assertSame(0, DB::table('purchase_settlements')->count(), 'Manual receipt is evidence only, never capture authority.');

        $replay = $this->app->make(CardToCardManualSubmissionService::class)->submit(
            'c2c.manual.submission.0001',
            $payment['user_id'],
            $payment['receipt']->reservationPublicId,
            $payment['receipt']->payableAmountIrr,
            $paidAt,
            hash('sha256', 'manual-receipt-evidence'),
            '6037991234567890',
            'Sender Name',
            'bank-reference-1',
            'private://telegram/receipt/opaque-1',
            $this->correlation('manual-submit-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($submission->submissionId, $replay->submissionId);

        $bank = $this->app->make(\App\Modules\Payments\CardToCard\Application\CardToCardBankTransactionService::class)->ingest(
            'fake',
            $this->observation('manual-late', $payment['receipt']->payableAmountIrr, $paidAt),
            'fake',
            $this->correlation('manual-bank'),
        );
        $review = $this->app->make(CardToCardMatchingService::class)->match($bank->publicId, $this->correlation('manual-match'));
        self::assertNotNull($review->reviewPublicId);
        self::assertStringStartsWith('review_pending:late', $review->outcome);

        $settlement = $this->app->make(CardToCardReviewDecisionService::class)->approve(
            $review->reviewPublicId,
            $payment['receipt']->reservationPublicId,
            $this->ownerAdministrator(),
            'Authoritative bank transaction confirms the submitted late receipt.',
            $this->correlation('manual-approve'),
        );
        self::assertSame($payment['receipt']->payableAmountIrr, $settlement->payableAmountIrr);
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->value('state'));
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame('accepted', DB::table('c2c_match_reviews')->where('public_id', $review->reviewPublicId)->value('state'));
    }

    public function test_fake_provider_polling_exact_match_captures_before_cursor_advances(): void
    {
        $payment = $this->payment('poll');
        $provider = new FakeBankTransactionVerificationProvider('fake');
        $provider->put(null, new BankTransactionPage([
            $this->observation('poll', $payment['receipt']->payableAmountIrr, $this->clock->value->modify('+2 minutes')),
        ], 'cursor-2'));

        $result = $this->app->make(CardToCardProviderPollingService::class)->poll(
            $provider,
            $this->correlation('poll'),
        );

        self::assertSame(1, $result['ingested']);
        self::assertSame(1, $result['matched']);
        self::assertSame(1, $result['captured']);
        self::assertSame(0, $result['reviewed']);
        self::assertSame('cursor-2', $result['next_cursor']);
        self::assertSame('cursor-2', DB::table('c2c_provider_cursors')->where('provider_code', 'fake')->value('cursor'));
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $payment['receipt']->paymentIntent->intentPublicId)->value('state'));
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'card_to_card')->count());
    }

    /** @return array{user_id:int,receipt:\App\Modules\Payments\CardToCard\Application\CardToCardPaymentReceipt} */
    private function payment(string $suffix): array
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'c2c.manual-poll.quote.'.$suffix,
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'c2c.manual-poll.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
        );
        $receipt = $this->app->make(CardToCardPaymentService::class)->create(
            'c2c.manual-poll.intent.'.$suffix,
            $user,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('payment-'.$suffix),
        );

        return ['user_id' => $user, 'receipt' => $receipt];
    }

    private function observation(string $suffix, int $amountIrr, DateTimeImmutable $occurredAt): BankTransactionObservation
    {
        return new BankTransactionObservation(
            'manual-poll-tx-'.$suffix,
            'manual-poll-event-'.$suffix,
            '4242424242424242',
            $amountIrr,
            'settled',
            $occurredAt,
            null,
            null,
            'manual-poll-reference-'.$suffix,
            hash('sha256', 'manual-poll-evidence:'.$suffix),
        );
    }

    private function configureMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'c2c.manual-poll.method',
            $administratorId,
            'card_to_card',
            true,
            false,
            1,
            'C2C manual/polling flow method.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'c2c.manual-poll.health',
            $administratorId,
            'card_to_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy C2C manual/polling provider.',
            $this->correlation('health'),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'c2c-manual-poll:'.$suffix);
    }
}
