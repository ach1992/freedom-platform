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
use App\Modules\Payments\GiftCard\Application\GiftCardReconciliationService;
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

final class GiftCardReconciliationClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement GFT-003 GFT-004 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
final class GiftCardReconciliationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private GiftCardReconciliationClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new GiftCardReconciliationClock(new DateTimeImmutable('2026-08-14T15:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        config()->set('payments.gift_card.code_lookup_key', str_repeat('r', 32));
        config()->set('payments.gift_card.code_lookup_key_version', 2);
        $this->configureMethod();
        $this->app->make(GiftCardTypeService::class)->register(
            'gift-recon',
            'Gift reconciliation type',
            'Steam',
            'GLOBAL',
            'IRR',
            'code_only',
            'automatic_only',
            null,
            'fake_gift_card',
        );
    }

    public function test_post_capture_provider_reversal_creates_critical_finding_without_second_financial_effect(): void
    {
        [$submission, $amount] = $this->submission('reversal');
        $provider = $this->provider();
        $provider->put('validate', $this->key($submission->publicId, 'validate'), $this->evidence(
            'validate', 'success', 'valid', 'validate-reversal', null, $amount,
        ));
        $provider->put('redeem', $this->key($submission->publicId, 'redeem'), $this->evidence(
            'redeem', 'success', 'redeemed', 'redeem-reversal', 'redeem-tx-reversal', $amount,
        ));

        $captured = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('capture-reversal'),
        );
        self::assertSame('captured', $captured->state);
        self::assertSame(1, DB::table('purchase_settlements')->count());

        $provider->put('status', $this->key($submission->publicId, 'status'), $this->evidence(
            'status', 'success', 'reversed', 'status-reversal', 'redeem-tx-reversal', $amount,
        ));
        $result = $this->app->make(GiftCardReconciliationService::class)->reconcile(
            $submission->publicId,
            $provider,
            $this->correlation('reconcile-reversal'),
        );

        self::assertSame('captured', $result->state);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('gift_card_redemptions')->count());
        self::assertSame(1, DB::table('gift_card_reconciliation_findings')
            ->where('finding_type', 'post_capture_provider_reversal')
            ->where('severity', 'critical')
            ->count());
    }

    public function test_uncertain_redeem_then_redeemed_status_stays_fail_closed_without_synthetic_redemption(): void
    {
        [$submission, $amount] = $this->submission('uncertain');
        $provider = $this->provider();
        $provider->put('validate', $this->key($submission->publicId, 'validate'), $this->evidence(
            'validate', 'success', 'valid', 'validate-uncertain', null, $amount,
        ));
        $provider->put('redeem', $this->key($submission->publicId, 'redeem'), $this->evidence(
            'redeem', 'uncertain', 'pending', 'redeem-uncertain', null, $amount,
        ));

        $pending = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('process-uncertain'),
        );
        self::assertSame('redeeming', $pending->state);
        self::assertSame(0, DB::table('gift_card_redemptions')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $provider->put('status', $this->key($submission->publicId, 'status'), $this->evidence(
            'status', 'success', 'redeemed', 'status-uncertain-redeemed', 'provider-redemption-uncertain', $amount,
        ));
        $reconciled = $this->app->make(GiftCardReconciliationService::class)->reconcile(
            $submission->publicId,
            $provider,
            $this->correlation('reconcile-uncertain'),
        );

        self::assertSame('redeeming', $reconciled->state);
        self::assertSame(0, DB::table('gift_card_redemptions')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('gift_card_reconciliation_findings')
            ->where('finding_type', 'provider_captured_local_redemption_missing')
            ->where('severity', 'critical')
            ->count());
    }

    /** @return array{0:\App\Modules\Payments\GiftCard\Application\GiftCardSubmissionReceipt,1:int} */
    private function submission(string $suffix): array
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'gift.recon.quote.'.$suffix,
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'gift.recon.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
        );
        $submission = $this->app->make(GiftCardSubmissionService::class)->submit(
            'gift.recon.submission.'.$suffix,
            'gift.recon.intent.'.$suffix,
            $user,
            $quote->quotePublicId,
            $eligibility->publicId,
            'gift-recon',
            $quote->finalPriceIrr,
            'IRR',
            'Steam',
            'GLOBAL',
            'RECON-CARD-'.strtoupper($suffix),
            null,
            null,
            null,
            null,
            $this->correlation('submit-'.$suffix),
        );

        return [$submission, $quote->finalPriceIrr];
    }

    private function provider(): FakeGiftCardVerificationProvider
    {
        return new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, false, true, false, true),
        );
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
            'gift-recon-event-'.$suffix,
            $transactionId,
            $amount,
            'IRR',
            'Steam',
            'GLOBAL',
            $this->clock->value->modify('+3 minutes'),
            hash('sha256', 'gift-recon-evidence:'.$suffix),
            ['source' => 'fake_reconciliation_test'],
        );
    }

    private function configureMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $service->configureMethod(
            'gift.recon.method',
            $administratorId,
            'gift_card',
            true,
            false,
            1,
            'Gift-card reconciliation method.',
            $this->correlation('method'),
        );
        $service->recordHealth(
            'gift.recon.health',
            $administratorId,
            'gift_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Gift-card reconciliation provider is healthy.',
            $this->correlation('health'),
        );
    }

    private function key(string $submissionPublicId, string $operation): string
    {
        return hash('sha256', 'gift-card:'.$submissionPublicId.':'.$operation);
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'gift-reconciliation-test:'.$suffix);
    }
}