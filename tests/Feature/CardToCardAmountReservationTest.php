<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FixedCardToCardAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function __construct(public int $value = 1000) {}

    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        if ($this->value < $minimumIrr || $this->value > $maximumIrr) {
            return $minimumIrr;
        }

        return $this->value;
    }
}

final class CardToCardAmountClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement C2C-001 C2C-002 C2C-004 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class CardToCardAmountReservationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private CardToCardAmountClock $clock;
    private FixedCardToCardAdjustmentGenerator $adjustments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new CardToCardAmountClock(new DateTimeImmutable('2026-08-14T09:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->adjustments = new FixedCardToCardAdjustmentGenerator();
        $this->app->instance(CardToCardAdjustmentGenerator::class, $this->adjustments);
        config()->set('payments.card_to_card.lookup_key', str_repeat('k', 32));
        $this->configureMethod();
    }

    public function test_destination_secrets_are_protected_and_only_masked_identity_is_returned(): void
    {
        $receipt = $this->destinationService()->register(
            'primary',
            '4242-4242-4242-4242',
            'Account Holder',
            true,
            1000,
            9990,
            30,
            1440,
            50_000_000,
            10,
            'manual',
            'Initial protected C2C destination.',
            $this->correlation('destination-primary'),
        );

        self::assertSame('424242******4242', $receipt->maskedCardNumber);
        self::assertSame('active', $receipt->state);
        $row = DB::table('c2c_destination_accounts')->where('id', $receipt->destinationId)->first();
        self::assertNotNull($row);
        self::assertNotSame('4242424242424242', $row->encrypted_card_number);
        self::assertStringNotContainsString('4242424242424242', (string) $row->encrypted_card_number);
        self::assertSame(64, strlen((string) $row->card_lookup_hash));
        self::assertNotSame(hash('sha256', '4242424242424242'), $row->card_lookup_hash);
        self::assertSame(1, DB::table('c2c_destination_account_events')->where('event_type', 'created')->count());

        $disabled = $this->destinationService()->setState(
            $receipt->publicId,
            false,
            'Maintenance rotation.',
            $this->correlation('destination-disable'),
        );
        self::assertSame('inactive', $disabled->state);
        self::assertSame(1, DB::table('c2c_destination_account_events')->where('event_type', 'deactivated')->count());
    }

    public function test_equal_base_amounts_receive_unique_active_payable_amounts_on_same_destination(): void
    {
        $this->registerDestination();
        [$firstUser, $firstQuote] = $this->quote('unique-a');
        [$secondUser, $secondQuote] = $this->quote('unique-b');
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $firstDecision = $eligibility->evaluate('c2c.eligibility.unique.a', $firstUser, $firstQuote);
        $secondDecision = $eligibility->evaluate('c2c.eligibility.unique.b', $secondUser, $secondQuote);
        $service = $this->app->make(CardToCardPaymentService::class);

        $first = $service->create(
            'c2c.intent.unique.a',
            $firstUser,
            $firstQuote,
            $firstDecision->publicId,
            $this->correlation('create-unique-a'),
        );
        $second = $service->create(
            'c2c.intent.unique.b',
            $secondUser,
            $secondQuote,
            $secondDecision->publicId,
            $this->correlation('create-unique-b'),
        );

        self::assertSame($first->baseAmountIrr, $second->baseAmountIrr);
        self::assertSame(1000, $first->adjustmentAmountIrr);
        self::assertSame(1001, $second->adjustmentAmountIrr);
        self::assertNotSame($first->payableAmountIrr, $second->payableAmountIrr);
        self::assertSame(2, DB::table('c2c_amount_reservations')->where('active_lock', 1)->count());
        self::assertSame('created', DB::table('payment_intents')->where('public_id', $first->paymentIntent->intentPublicId)->value('state'));
    }

    public function test_creation_replay_returns_same_intent_and_reservation_without_new_amount(): void
    {
        $this->registerDestination();
        [$user, $quote] = $this->quote('replay');
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate('c2c.eligibility.replay', $user, $quote);
        $service = $this->app->make(CardToCardPaymentService::class);

        $first = $service->create('c2c.intent.replay', $user, $quote, $decision->publicId, $this->correlation('replay-first'));
        $second = $service->create('c2c.intent.replay', $user, $quote, $decision->publicId, $this->correlation('replay-second'));

        self::assertTrue($second->replayed);
        self::assertSame($first->paymentIntent->intentPublicId, $second->paymentIntent->intentPublicId);
        self::assertSame($first->reservationId, $second->reservationId);
        self::assertSame($first->payableAmountIrr, $second->payableAmountIrr);
        self::assertSame(1, DB::table('payment_intents')->where('payment_method_code', 'card_to_card')->count());
        self::assertSame(1, DB::table('c2c_amount_reservations')->count());
    }

    public function test_expired_reservation_releases_unique_amount_without_deleting_history(): void
    {
        $this->registerDestination(reservationMinutes: 5, lateReviewMinutes: 60);
        [$firstUser, $firstQuote] = $this->quote('expire-a');
        $firstDecision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate('c2c.eligibility.expire.a', $firstUser, $firstQuote);
        $service = $this->app->make(CardToCardPaymentService::class);
        $first = $service->create('c2c.intent.expire.a', $firstUser, $firstQuote, $firstDecision->publicId, $this->correlation('expire-a'));

        $this->clock->value = $this->clock->value->modify('+6 minutes');
        [$secondUser, $secondQuote] = $this->quote('expire-b');
        $secondDecision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate('c2c.eligibility.expire.b', $secondUser, $secondQuote);
        $second = $service->create('c2c.intent.expire.b', $secondUser, $secondQuote, $secondDecision->publicId, $this->correlation('expire-b'));

        self::assertSame($first->payableAmountIrr, $second->payableAmountIrr);
        self::assertSame(2, DB::table('c2c_amount_reservations')->count());
        self::assertNull(DB::table('c2c_amount_reservations')->where('id', $first->reservationId)->value('active_lock'));
        self::assertSame('expired', DB::table('c2c_amount_reservations')->where('id', $first->reservationId)->value('release_reason'));
        self::assertSame(1, DB::table('c2c_amount_reservations')->where('active_lock', 1)->count());
    }

    public function test_database_rejects_forged_active_payable_collision_and_identity_mutation(): void
    {
        $this->registerDestination();
        [$firstUser, $firstQuote] = $this->quote('db-a');
        [$secondUser, $secondQuote] = $this->quote('db-b');
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $firstDecision = $eligibility->evaluate('c2c.eligibility.db.a', $firstUser, $firstQuote);
        $secondDecision = $eligibility->evaluate('c2c.eligibility.db.b', $secondUser, $secondQuote);
        $service = $this->app->make(CardToCardPaymentService::class);
        $first = $service->create('c2c.intent.db.a', $firstUser, $firstQuote, $firstDecision->publicId, $this->correlation('db-a'));
        $second = $service->create('c2c.intent.db.b', $secondUser, $secondQuote, $secondDecision->publicId, $this->correlation('db-b'));

        $this->assertQueryRejected(static fn (): int => DB::table('c2c_amount_reservations')->where('id', $second->reservationId)->update([
            'payable_amount_irr' => $first->payableAmountIrr,
        ]));
        $this->assertQueryRejected(static fn (): int => DB::table('c2c_amount_reservations')->where('id', $first->reservationId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('c2c_destination_accounts')->where('public_id', $first->destinationPublicId)->delete());
    }

    private function configureMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'c2c.method.foundation',
            $administratorId,
            'card_to_card',
            true,
            false,
            1,
            'Card-to-card exact amount foundation.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'c2c.health.foundation',
            $administratorId,
            'card_to_card',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy card-to-card foundation observation.',
            $this->correlation('health'),
        );
    }

    private function registerDestination(int $reservationMinutes = 30, int $lateReviewMinutes = 1440): void
    {
        $this->destinationService()->register(
            'primary',
            '4242424242424242',
            'Account Holder',
            true,
            1000,
            9990,
            $reservationMinutes,
            $lateReviewMinutes,
            null,
            10,
            'manual',
            'Test C2C destination.',
            $this->correlation('destination'),
        );
    }

    /** @return array{0:int,1:string} */
    private function quote(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'c2c.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );

        return [$userId, $quote->quotePublicId];
    }

    private function destinationService(): CardToCardDestinationService
    {
        return $this->app->make(CardToCardDestinationService::class);
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'c2c:'.$suffix);
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
