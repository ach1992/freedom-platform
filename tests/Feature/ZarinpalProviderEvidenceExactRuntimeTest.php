<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ZarinpalProviderEvidenceExactRuntimeTransport implements ZarinpalTransport
{
    public function request(string $merchantId, int $amountIrr, string $callbackUrl, string $description, string $orderId): ZarinpalRequestResult
    {
        return ZarinpalRequestResult::accepted('A'.str_repeat('8', 35));
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        return ZarinpalVerifyResult::verified('260003099', 100);
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        return ZarinpalInquiryResult::available('PAID');
    }

    public function unverified(string $merchantId): array
    {
        return [];
    }
}

/** @requirement IPG-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class ZarinpalProviderEvidenceExactRuntimeTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Zarinpal provider-evidence exact runtime identity requires MariaDB/MySQL.');
        }
        $this->seed();
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
        $this->app->instance(ZarinpalTransport::class, new ZarinpalProviderEvidenceExactRuntimeTransport);
    }

    public function test_case_distinct_claim_hash_is_not_treated_as_exact_runtime_replay(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('runtime-hash-conflict');
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zpal.runtime.exact.intent.000001',
            $userId,
            $quotePublicId,
            $decisionPublicId,
            'zarinpal',
            $this->correlation('intent'),
        );
        $service = $this->app->make(ZarinpalPaymentService::class);
        $started = $service->initiate(
            'zpal.runtime.exact.request.000001',
            $intent->intentPublicId,
            $this->correlation('initiate'),
        );
        self::assertSame('redirectable', $started->state->value);
        self::assertSame(0, DB::table('orders')->count());

        $intentId = (int) DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('id');
        $request = DB::table('zarinpal_payment_requests')->where('payment_intent_id', $intentId)->first();
        self::assertNotNull($request);
        $authority = (string) $request->authority;
        $amountIrr = (int) $request->amount_irr;
        $canonicalHash = hash('sha256', json_encode([
            'provider' => 'zarinpal',
            'authority' => $authority,
            'provider_ref_id' => '260003099',
            'amount_irr' => $amountIrr,
            'currency' => 'IRR',
            'result' => 'verified',
        ], JSON_THROW_ON_ERROR));
        $caseDistinctHash = strtoupper($canonicalHash);
        self::assertNotSame($canonicalHash, $caseDistinctHash);

        DB::table('zarinpal_provider_evidence_claims')->insert([
            'zarinpal_payment_request_id' => (int) $request->id,
            'authority' => $authority,
            'provider_ref_id' => '260003099',
            'evidence_payload_hash' => $caseDistinctHash,
            'evidence_disposition' => 'settled',
            'amount_irr' => $amountIrr,
            'currency' => 'IRR',
            'created_at' => now('UTC'),
        ]);

        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame(
            0,
            DB::table('zarinpal_provider_evidence_claims')
                ->where('zarinpal_payment_request_id', (int) $request->id)
                ->where('evidence_payload_hash', $canonicalHash)
                ->count(),
        );

        $receipt = $service->handleCallback(
            $authority,
            'OK',
            $this->correlation('callback'),
        );

        self::assertSame('manual_review', $receipt->state->value);
        self::assertTrue($receipt->requiresManualReview);
        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame($caseDistinctHash, (string) DB::table('zarinpal_provider_evidence_claims')->value('evidence_payload_hash'));
        self::assertSame(0, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame('pending_manual_review', (string) DB::table('payment_intents')->where('id', $intentId)->value('state'));

        $finding = DB::table('zarinpal_reconciliation_findings')->first();
        self::assertNotNull($finding);
        self::assertSame('provider_verification_conflict', (string) $finding->finding_type);
        self::assertSame('critical', (string) $finding->severity);
        self::assertSame('verified', (string) $finding->observed_result);
        self::assertSame('260003099', (string) $finding->provider_ref_id);
        self::assertSame($canonicalHash, (string) $finding->evidence_hash);
    }

    /** @return array{0:int,1:string,2:string} */
    private function plainContext(string $suffix): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'zpal.runtime.exact.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
            $this->correlation($suffix.'-quote'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'zpal.runtime.exact.method.'.substr(hash('sha256', $suffix), 0, 16),
            $administratorId,
            'zarinpal',
            true,
            false,
            1,
            'Zarinpal exact provider-evidence runtime test configuration.',
            $this->correlation($suffix.'-method'),
        );
        $eligibility->recordHealth(
            'zpal.runtime.exact.health.'.substr(hash('sha256', $suffix), 0, 16),
            $administratorId,
            'zarinpal',
            true,
            now('UTC')->addMinutes(10)->toDateTimeImmutable(),
            'Healthy exact provider-evidence runtime observation.',
            $this->correlation($suffix.'-health'),
        );
        $decision = $eligibility->evaluate(
            'zpal.runtime.exact.eligibility.'.$suffix,
            $userId,
            $quote->quotePublicId,
        );

        return [$userId, $quote->quotePublicId, $decision->publicId];
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'zarinpal-provider-evidence-exact-runtime:'.$suffix);
    }
}
