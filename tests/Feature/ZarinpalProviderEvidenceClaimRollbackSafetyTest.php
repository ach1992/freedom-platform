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
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class ZarinpalProviderEvidenceClaimRollbackTransport implements ZarinpalTransport
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
final class ZarinpalProviderEvidenceClaimRollbackSafetyTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Zarinpal provider-evidence rollback safety requires MariaDB/MySQL.');
        }
        $this->seed();
        config()->set('app.url', 'http://localhost');
        config()->set('services.zarinpal.enabled', true);
        config()->set('services.zarinpal.merchant_id', '00000000-0000-0000-0000-000000000000');
        config()->set('services.zarinpal.callback_url', 'http://localhost/payments/zarinpal/callback');
        $this->app->instance(ZarinpalTransport::class, new ZarinpalProviderEvidenceClaimRollbackTransport);
    }

    protected function tearDown(): void
    {
        try {
            if (! Schema::hasTable('zarinpal_provider_evidence_claims')) {
                $this->migration()->up();
            }
            if (isset($this->app)) {
                $this->truncateDatabaseTables();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_standalone_legacy_provider_evidence_claim_blocks_semantic_rollback(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('standalone-claim');
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zpal.rollback.claim.intent.000001',
            $userId,
            $quotePublicId,
            $decisionPublicId,
            'zarinpal',
            $this->correlation('standalone-intent'),
        );
        $legacy = $this->app->make(ZarinpalPaymentService::class)->initiate(
            'zpal.rollback.claim.request.000001',
            $intent->intentPublicId,
            $this->correlation('standalone-initiate'),
        );
        self::assertSame('redirectable', $legacy->state->value);
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('zarinpal_payment_verifications')->count());

        $intentId = (int) DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('id');
        $requestId = (int) DB::table('zarinpal_payment_requests')->where('payment_intent_id', $intentId)->value('id');
        $authority = (string) DB::table('zarinpal_payment_requests')->where('id', $requestId)->value('authority');
        $amountIrr = (int) DB::table('zarinpal_payment_requests')->where('id', $requestId)->value('amount_irr');
        $currency = (string) DB::table('zarinpal_payment_requests')->where('id', $requestId)->value('currency');

        DB::table('zarinpal_provider_evidence_claims')->insert([
            'zarinpal_payment_request_id' => $requestId,
            'authority' => $authority,
            'provider_ref_id' => '260003098',
            'evidence_payload_hash' => hash('sha256', 'standalone-legacy-provider-evidence-claim'),
            'evidence_disposition' => 'settled',
            'amount_irr' => $amountIrr,
            'currency' => $currency,
            'created_at' => now('UTC'),
        ]);

        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame(0, $this->matchingBaselineClaimCount());

        $this->assertRollbackRejected();

        self::assertTrue(Schema::hasTable('zarinpal_provider_evidence_claims'));
        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame($requestId, (int) DB::table('zarinpal_provider_evidence_claims')->value('zarinpal_payment_request_id'));
    }

    public function test_exact_legacy_settled_claim_redundant_with_baseline_verification_can_roll_back(): void
    {
        [$userId, $quotePublicId, $decisionPublicId] = $this->plainContext('redundant-claim');
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'zpal.rollback.redundant.intent.000001',
            $userId,
            $quotePublicId,
            $decisionPublicId,
            'zarinpal',
            $this->correlation('redundant-intent'),
        );
        $service = $this->app->make(ZarinpalPaymentService::class);
        $service->initiate(
            'zpal.rollback.redundant.request.000001',
            $intent->intentPublicId,
            $this->correlation('redundant-initiate'),
        );
        $verified = $service->handleCallback(
            'A'.str_repeat('8', 35),
            'OK',
            $this->correlation('redundant-callback'),
        );

        self::assertSame('verified', $verified->state->value);
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame(1, $this->matchingBaselineClaimCount());
        self::assertSame(0, DB::table('zarinpal_verified_unsettled_evidence')->count());
        self::assertSame(0, DB::table('zarinpal_reconciliation_findings')->count());

        $this->migration()->down();

        self::assertFalse(Schema::hasTable('zarinpal_provider_evidence_claims'));
        self::assertFalse(Schema::hasTable('zarinpal_verified_unsettled_evidence'));
        self::assertFalse(Schema::hasTable('zarinpal_reconciliation_findings'));
        self::assertSame(1, DB::table('zarinpal_payment_verifications')->count());
        self::assertSame(0, DB::table('orders')->count());

        $this->migration()->up();

        self::assertTrue(Schema::hasTable('zarinpal_provider_evidence_claims'));
        self::assertSame(1, DB::table('zarinpal_provider_evidence_claims')->count());
        self::assertSame(1, $this->matchingBaselineClaimCount());
    }

    private function matchingBaselineClaimCount(): int
    {
        return DB::table('zarinpal_provider_evidence_claims as claim_row')
            ->join('zarinpal_payment_verifications as verification_row', function ($join): void {
                $join->on('verification_row.zarinpal_payment_request_id', '=', 'claim_row.zarinpal_payment_request_id')
                    ->on('verification_row.authority', '=', 'claim_row.authority')
                    ->on('verification_row.provider_ref_id', '=', 'claim_row.provider_ref_id')
                    ->on('verification_row.evidence_payload_hash', '=', 'claim_row.evidence_payload_hash')
                    ->on('verification_row.amount_irr', '=', 'claim_row.amount_irr')
                    ->on('verification_row.currency', '=', 'claim_row.currency');
            })
            ->where('claim_row.evidence_disposition', 'settled')
            ->count();
    }

    /** @return array{0:int,1:string,2:string} */
    private function plainContext(string $suffix): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'zpal.rollback.claim.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, now('UTC')->addMinutes(30)->toDateTimeImmutable()),
            $this->correlation($suffix.'-quote'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'zpal.rollback.claim.method.'.substr(hash('sha256', $suffix), 0, 16),
            $administratorId,
            'zarinpal',
            true,
            false,
            1,
            'Zarinpal provider-evidence rollback safety test configuration.',
            $this->correlation($suffix.'-method'),
        );
        $eligibility->recordHealth(
            'zpal.rollback.claim.health.'.substr(hash('sha256', $suffix), 0, 16),
            $administratorId,
            'zarinpal',
            true,
            now('UTC')->addMinutes(10)->toDateTimeImmutable(),
            'Healthy provider-evidence rollback safety observation.',
            $this->correlation($suffix.'-health'),
        );
        $decision = $eligibility->evaluate(
            'zpal.rollback.claim.eligibility.'.$suffix,
            $userId,
            $quote->quotePublicId,
        );

        return [$userId, $quote->quotePublicId, $decision->publicId];
    }

    private function assertRollbackRejected(): void
    {
        try {
            $this->migration()->down();
            self::fail('Standalone provider-evidence authority must refuse semantic rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Cannot roll back Zarinpal pre-payment Order authority while durable pre-payment or reconciliation authority exists.',
                $exception->getMessage(),
            );
        }
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_07_000100_enable_zarinpal_pre_payment_order_authority.php');

        return $migration;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'zarinpal-provider-claim-rollback:'.$suffix);
    }
}
