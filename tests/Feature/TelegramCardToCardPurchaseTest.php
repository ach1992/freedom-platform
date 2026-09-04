<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderOpeningReceipt;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\CardToCard\Application\TelegramCustomerPurchaseCardToCardPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentEligibilityDecisionReceipt;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use App\Shared\Application\RestrictedData;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TelegramCardToCardPurchaseClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class TelegramCardToCardPurchaseAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        return max($minimumIrr, min(1000, $maximumIrr));
    }
}

/** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PAY-003 PRO-001 C2C-001 C2C-004 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
final class TelegramCardToCardPurchaseTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private TelegramCardToCardPurchaseClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new TelegramCardToCardPurchaseClock(new DateTimeImmutable('2026-09-04T12:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        DB::statement('SET timestamp = '.$this->clock->value->getTimestamp());
        $this->app->instance(CardToCardAdjustmentGenerator::class, new TelegramCardToCardPurchaseAdjustmentGenerator);
        config()->set('payments.card_to_card.lookup_key', str_repeat('k', 32));
        $this->configureCardToCardMethod();
        $this->registerDestination();
    }

    public function test_reserve_revalidates_checkout_and_replays_one_safe_card_to_card_authority(): void
    {
        [$userId, $quote, $decision, $order] = $this->purchaseContext('reserve');
        $service = $this->app->make(TelegramCustomerPurchaseCardToCardPaymentService::class);
        $operationKey = hash('sha256', 'telegram-c2c-reserve-test');

        $first = $service->reserveForSelf(
            $userId,
            $userId,
            $order->orderPublicId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decision->publicId,
            $decision->configurationSnapshotHash,
            $operationKey,
        );
        $replay = $service->reserveForSelf(
            $userId,
            $userId,
            $order->orderPublicId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decision->publicId,
            $decision->configurationSnapshotHash,
            $operationKey,
        );
        $newInteractionReplay = $service->reserveForSelf(
            $userId,
            $userId,
            $order->orderPublicId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decision->publicId,
            $decision->configurationSnapshotHash,
            hash('sha256', 'telegram-c2c-reserve-after-back-test'),
        );

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->paymentIntentPublicId, $replay->paymentIntentPublicId);
        self::assertSame($first->reservationPublicId, $replay->reservationPublicId);
        self::assertTrue($newInteractionReplay->replayed);
        self::assertSame($first->paymentIntentPublicId, $newInteractionReplay->paymentIntentPublicId);
        self::assertSame($first->reservationPublicId, $newInteractionReplay->reservationPublicId);
        self::assertSame($quote->finalPriceIrr, $first->baseAmountIrr);
        self::assertSame(1000, $first->adjustmentAmountIrr);
        self::assertSame($quote->finalPriceIrr + 1000, $first->payableAmountIrr);
        self::assertSame('424242******4242', $first->maskedCardNumber);
        self::assertSame(1, DB::table('payment_intents')->where('payment_method_code', 'card_to_card')->count());
        self::assertSame(1, DB::table('c2c_amount_reservations')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame('awaiting_payment', DB::table('orders')->where('public_id', $order->orderPublicId)->value('state'));
    }

    public function test_destination_reveal_is_restricted_owned_and_expires_with_payable_window(): void
    {
        [$userId, $quote, $decision, $order] = $this->purchaseContext('destination');
        $service = $this->app->make(TelegramCustomerPurchaseCardToCardPaymentService::class);
        $reservation = $service->reserveForSelf(
            $userId,
            $userId,
            $order->orderPublicId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decision->publicId,
            $decision->configurationSnapshotHash,
            hash('sha256', 'telegram-c2c-destination-test'),
        );

        $destination = $service->destinationForSelf($userId, $userId, $reservation->reservationPublicId);
        self::assertInstanceOf(RestrictedData::class, $destination);
        self::assertInstanceOf(RestrictedData::class, $destination->cardNumber);
        self::assertSame('4242424242424242', $destination->cardNumber->reveal());
        self::assertSame('424242******4242', $destination->maskedCardNumber);
        self::assertSame($reservation->payableAmountIrr, $destination->payableAmountIrr);
        self::assertNotSame('4242424242424242', DB::table('c2c_destination_accounts')->value('encrypted_card_number'));

        $this->expectException(AuthorizationException::class);
        $this->clock->value = $reservation->expiresAt->modify('+1 second');
        $service->destinationForSelf($userId, $userId, $reservation->reservationPublicId);
    }

    public function test_cross_user_destination_reveal_fails_closed(): void
    {
        [$userId, $quote, $decision, $order] = $this->purchaseContext('cross-user');
        $service = $this->app->make(TelegramCustomerPurchaseCardToCardPaymentService::class);
        $reservation = $service->reserveForSelf(
            $userId,
            $userId,
            $order->orderPublicId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decision->publicId,
            $decision->configurationSnapshotHash,
            hash('sha256', 'telegram-c2c-cross-user-test'),
        );
        $otherUserId = $this->quoteUser('customer');

        $this->expectException(AuthorizationException::class);
        $service->destinationForSelf($otherUserId, $otherUserId, $reservation->reservationPublicId);
    }

    /** @return array{0:int,1:QuoteReceipt,2:PaymentEligibilityDecisionReceipt,3:PurchaseOrderOpeningReceipt} */
    private function purchaseContext(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'telegram.c2c.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('quote-'.$suffix),
        );
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'telegram.c2c.eligibility.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        $order = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('order-'.$suffix),
        );

        return [$userId, $quote, $decision, $order];
    }

    private function configureCardToCardMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $service->configureMethod(
            'telegram.c2c.method',
            $administratorId,
            'card_to_card',
            true,
            false,
            1,
            'Telegram card-to-card purchase test method.',
            $this->correlation('method'),
        );
        $service->recordHealth(
            'telegram.c2c.health',
            $administratorId,
            'card_to_card',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Telegram card-to-card purchase test health.',
            $this->correlation('health'),
        );
    }

    private function registerDestination(): void
    {
        $this->app->make(CardToCardDestinationService::class)->register(
            'telegram-primary',
            '4242424242424242',
            'Test Account Holder',
            true,
            1000,
            9990,
            30,
            1440,
            null,
            10,
            'manual',
            'Telegram C2C test destination.',
            $this->correlation('destination'),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'telegram-c2c:'.$suffix);
    }
}
