<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\GiftCard\Application\GiftCardTypeService;
use App\Modules\Payments\GiftCard\Application\TelegramCustomerPurchaseGiftCardPaymentService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TelegramGiftCardManualClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 GFT-001 GFT-002 GFT-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class TelegramGiftCardManualPaymentTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private TelegramGiftCardManualClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new TelegramGiftCardManualClock(new DateTimeImmutable('2026-09-06T20:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        config()->set('payments.gift_card.code_lookup_key', str_repeat('m', 32));
        config()->set('payments.gift_card.code_lookup_key_version', 11);
        $this->configureGiftCardMethod();
    }

    public function test_customer_projection_exposes_only_active_manual_code_capable_types_and_denies_cross_actor_access(): void
    {
        $manualCode = $this->registerType('tg-manual-code', 'code_only', 'manual_only');
        $manualEither = $this->registerType('tg-manual-either', 'either', 'manual_only');
        $this->registerType('tg-auto-code', 'code_only', 'automatic_only');
        $this->registerType('tg-manual-image', 'image_only', 'manual_only');
        $inactive = $this->registerType('tg-manual-inactive', 'code_only', 'manual_only');
        $this->app->make(GiftCardTypeService::class)->setActive($inactive->typeCode, false);
        $purchase = $this->purchase('projection');

        $types = $this->app->make(TelegramCustomerPurchaseGiftCardPaymentService::class)->availableTypesForSelf(
            $purchase['user_id'],
            $purchase['user_id'],
            $purchase['order_public_id'],
            $purchase['quote_public_id'],
            $purchase['quote_configuration_hash'],
            $purchase['decision_public_id'],
            $purchase['decision_configuration_hash'],
        );

        self::assertSame([$manualCode->typeCode, $manualEither->typeCode], array_map(
            static fn ($type): string => $type->typeCode,
            $types,
        ));
        self::assertSame($manualCode->configurationHash, $types[0]->configurationHash);
        self::assertSame('IRR', $types[0]->faceCurrency);
        self::assertSame('Steam', $types[0]->brand);
        self::assertSame('GLOBAL', $types[0]->region);

        $this->expectException(AuthorizationException::class);
        $this->app->make(TelegramCustomerPurchaseGiftCardPaymentService::class)->availableTypesForSelf(
            $purchase['user_id'] + 1,
            $purchase['user_id'],
            $purchase['order_public_id'],
            $purchase['quote_public_id'],
            $purchase['quote_configuration_hash'],
            $purchase['decision_public_id'],
            $purchase['decision_configuration_hash'],
        );
    }

    public function test_manual_code_submission_protects_secret_and_replays_without_provider_or_second_financial_effect(): void
    {
        $type = $this->registerType('tg-manual-submit', 'code_only', 'manual_only');
        $purchase = $this->purchase('submit');
        $code = 'STEAM-TG-SECRET-9081726354';
        $operationKey = hash('sha256', 'telegram-gift-card-manual-submit');
        $service = $this->app->make(TelegramCustomerPurchaseGiftCardPaymentService::class);

        $first = $service->submitCodeForSelf(
            $purchase['user_id'],
            $purchase['user_id'],
            $purchase['order_public_id'],
            $purchase['quote_public_id'],
            $purchase['quote_configuration_hash'],
            $purchase['decision_public_id'],
            $purchase['decision_configuration_hash'],
            $type->typeCode,
            $type->configurationHash,
            $purchase['amount'],
            $code,
            $operationKey,
        );
        $replay = $service->submitCodeForSelf(
            $purchase['user_id'],
            $purchase['user_id'],
            $purchase['order_public_id'],
            $purchase['quote_public_id'],
            $purchase['quote_configuration_hash'],
            $purchase['decision_public_id'],
            $purchase['decision_configuration_hash'],
            $type->typeCode,
            $type->configurationHash,
            $purchase['amount'],
            $code,
            $operationKey,
        );

        self::assertSame('pending_manual_review', $first->state);
        self::assertSame($first->submissionPublicId, $replay->submissionPublicId);
        self::assertSame($first->paymentIntentPublicId, $replay->paymentIntentPublicId);
        self::assertSame($first->reviewPublicId, $replay->reviewPublicId);
        self::assertTrue($replay->replayed);
        self::assertStringNotContainsString($code, $first->maskedCode);

        $submission = DB::table('gift_card_submissions')->where('public_id', $first->submissionPublicId)->first();
        self::assertNotNull($submission);
        self::assertSame('pending_manual_review', $submission->state);
        self::assertNotSame($code, $submission->encrypted_code);
        self::assertStringNotContainsString($code, (string) $submission->encrypted_code);
        self::assertSame(
            $code,
            $this->app->make(StringEncrypter::class)->decryptString((string) $submission->encrypted_code),
        );
        self::assertSame(hash_hmac('sha256', $code, str_repeat('m', 32)), $submission->code_lookup_hash);
        self::assertStringNotContainsString($code, (string) $submission->masked_code);

        self::assertSame(1, DB::table('gift_card_submissions')->count());
        self::assertSame(1, DB::table('payment_intents')->where('public_id', $first->paymentIntentPublicId)->count());
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $first->paymentIntentPublicId)->value('state'));
        self::assertSame(1, DB::table('gift_card_reviews')->where('public_id', $first->reviewPublicId)->count());
        self::assertSame(0, DB::table('gift_card_provider_events')->count());
        self::assertSame(0, DB::table('gift_card_redemptions')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame('awaiting_payment', DB::table('purchase_orders')->where('public_id', $purchase['order_public_id'])->value('state'));
    }

    public function test_stale_inactive_type_fails_closed_before_gift_card_or_payment_state_is_created(): void
    {
        $type = $this->registerType('tg-stale-type', 'code_only', 'manual_only');
        $purchase = $this->purchase('stale-type');
        $this->app->make(GiftCardTypeService::class)->setActive($type->typeCode, false);

        try {
            $this->app->make(TelegramCustomerPurchaseGiftCardPaymentService::class)->submitCodeForSelf(
                $purchase['user_id'],
                $purchase['user_id'],
                $purchase['order_public_id'],
                $purchase['quote_public_id'],
                $purchase['quote_configuration_hash'],
                $purchase['decision_public_id'],
                $purchase['decision_configuration_hash'],
                $type->typeCode,
                $type->configurationHash,
                $purchase['amount'],
                'STALE-GIFT-CODE-123456',
                hash('sha256', 'telegram-gift-card-stale-type'),
            );
            self::fail('Expected stale Gift Card type to fail closed.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        self::assertSame(0, DB::table('gift_card_submissions')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('gift_card_reviews')->count());
        self::assertSame(0, DB::table('gift_card_provider_events')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    /** @return array{user_id:int,quote_public_id:string,quote_configuration_hash:string,decision_public_id:string,decision_configuration_hash:string,order_public_id:string,amount:int} */
    private function purchase(string $suffix): array
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'tg.gift.quote.'.$suffix,
            $user,
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
            'tg.gift.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
        );
        self::assertContains('gift_card', array_column($decision->methods, 'method_code'));
        $order = $this->app->make(TelegramCustomerPurchaseOrder::class)->openForSelf(
            $user,
            $user,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $this->correlation('order-'.$suffix),
        );

        return [
            'user_id' => $user,
            'quote_public_id' => $quote->quotePublicId,
            'quote_configuration_hash' => $quote->configurationSnapshotHash,
            'decision_public_id' => $decision->publicId,
            'decision_configuration_hash' => $decision->configurationSnapshotHash,
            'order_public_id' => $order->orderPublicId,
            'amount' => $quote->finalPriceIrr,
        ];
    }

    private function registerType(string $typeCode, string $submissionMode, string $verificationMode): \App\Modules\Payments\GiftCard\Application\GiftCardTypeReceipt
    {
        return $this->app->make(GiftCardTypeService::class)->register(
            $typeCode,
            'Steam Gift Card '.$typeCode,
            'Steam',
            'GLOBAL',
            'IRR',
            $submissionMode,
            $verificationMode,
            null,
            'fake_gift_card',
        );
    }

    private function configureGiftCardMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'tg.gift.method.foundation',
            $administratorId,
            'gift_card',
            true,
            false,
            1,
            'Telegram Gift Card manual-review test configuration.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'tg.gift.health.foundation',
            $administratorId,
            'gift_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy Telegram Gift Card manual-review test observation.',
            $this->correlation('health'),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'telegram-gift-card-manual-test:'.$suffix);
    }
}
