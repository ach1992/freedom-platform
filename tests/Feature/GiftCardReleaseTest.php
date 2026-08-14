<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderCapabilities;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\GiftCardPaymentService;
use App\Modules\Payments\GiftCard\Application\GiftCardReleaseService;
use App\Modules\Payments\GiftCard\Application\GiftCardSubmissionService;
use App\Modules\Payments\GiftCard\Application\GiftCardTypeService;
use App\Modules\Payments\GiftCard\Infrastructure\FakeGiftCardVerificationProvider;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GiftCardReleaseClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement GFT-003 GFT-004 PAY-002 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
final class GiftCardReleaseTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private GiftCardReleaseClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new GiftCardReleaseClock(new DateTimeImmutable('2026-08-14T16:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        config()->set('payments.gift_card.code_lookup_key', str_repeat('l', 32));
        config()->set('payments.gift_card.code_lookup_key_version', 1);
        $this->configureMethod();
        $this->app->make(GiftCardTypeService::class)->register(
            'gift-release',
            'Gift release type',
            'Steam',
            'GLOBAL',
            'IRR',
            'code_only',
            'automatic_only',
            null,
            'fake_gift_card',
        );
    }

    public function test_rejected_redeem_after_reservation_can_release_once_with_authoritative_provider_event(): void
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'gift.release.quote',
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'gift.release.eligibility',
            $user,
            $quote->quotePublicId,
        );
        $submission = $this->app->make(GiftCardSubmissionService::class)->submit(
            'gift.release.submission',
            'gift.release.intent',
            $user,
            $quote->quotePublicId,
            $eligibility->publicId,
            'gift-release',
            $quote->finalPriceIrr,
            'IRR',
            'Steam',
            'GLOBAL',
            'RELEASE-CARD-0001',
            null,
            null,
            null,
            null,
            $this->correlation('submit'),
        );

        $provider = new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, true, true, true, true),
        );
        $provider->put('validate', $this->key($submission->publicId, 'validate'), $this->evidence(
            'validate', 'success', 'valid', 'validate', null, $quote->finalPriceIrr,
        ));
        $provider->put('reserve', $this->key($submission->publicId, 'reserve'), $this->evidence(
            'reserve', 'success', 'reserved', 'reserve', 'reserve-tx-1', $quote->finalPriceIrr,
        ));
        $provider->put('redeem', $this->key($submission->publicId, 'redeem'), $this->evidence(
            'redeem', 'rejected', 'blocked', 'redeem-rejected', null, $quote->finalPriceIrr,
        ));

        $rejected = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('process'),
        );
        self::assertSame('rejected', $rejected->state);
        self::assertSame('failed', DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->value('state'));
        self::assertSame(1, DB::table('gift_card_provider_events')->where('operation', 'reserve')->where('outcome', 'success')->count());
        self::assertSame(0, DB::table('gift_card_redemptions')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $provider->put('release', $this->key($submission->publicId, 'release'), $this->evidence(
            'release', 'success', 'released', 'release', 'release-tx-1', $quote->finalPriceIrr,
        ));
        $released = $this->app->make(GiftCardReleaseService::class)->release(
            $submission->publicId,
            $provider,
            $this->correlation('release'),
        );
        self::assertSame('released', $released->state);
        self::assertFalse($released->replayed);
        self::assertSame(1, DB::table('gift_card_provider_events')->where('operation', 'release')->where('outcome', 'success')->count());
        self::assertSame(0, DB::table('gift_card_redemptions')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $replay = $this->app->make(GiftCardReleaseService::class)->release(
            $submission->publicId,
            $provider,
            $this->correlation('release-replay'),
        );
        self::assertSame('released', $replay->state);
        self::assertTrue($replay->replayed);
        self::assertSame(1, DB::table('gift_card_provider_events')->where('operation', 'release')->count());
    }

    private function evidence(
        string $operation,
        string $outcome,
        string $status,
        string $suffix,
        ?string $transactionId,
        int $amount,
    ): GiftCardProviderEvidence {
        return new GiftCardProviderEvidence(
            $operation,
            $outcome,
            $status,
            'gift-release-event-'.$suffix,
            $transactionId,
            $amount,
            'IRR',
            'Steam',
            'GLOBAL',
            $this->clock->value->modify('+3 minutes'),
            hash('sha256', 'gift-release-evidence:'.$suffix),
            ['source' => 'fake_release_test'],
        );
    }

    private function configureMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $service->configureMethod(
            'gift.release.method',
            $administratorId,
            'gift_card',
            true,
            false,
            1,
            'Gift-card release test method.',
            $this->correlation('method'),
        );
        $service->recordHealth(
            'gift.release.health',
            $administratorId,
            'gift_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Gift-card release provider is healthy.',
            $this->correlation('health'),
        );
    }

    private function key(string $submissionPublicId, string $operation): string
    {
        return hash('sha256', 'gift-card:'.$submissionPublicId.':'.$operation);
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'gift-card-release-test:'.$suffix);
    }
}
