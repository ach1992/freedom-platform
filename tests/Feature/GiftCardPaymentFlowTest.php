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
use App\Modules\Payments\GiftCard\Application\GiftCardReviewDecisionService;
use App\Modules\Payments\GiftCard\Application\GiftCardSubmissionService;
use App\Modules\Payments\GiftCard\Application\GiftCardTypeService;
use App\Modules\Payments\GiftCard\Infrastructure\FakeGiftCardVerificationProvider;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class GiftCardFlowClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement GFT-001 GFT-002 GFT-003 GFT-004 PAY-002 PAY-003 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
final class GiftCardPaymentFlowTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private GiftCardFlowClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new GiftCardFlowClock(new DateTimeImmutable('2026-08-14T14:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        config()->set('payments.gift_card.code_lookup_key', str_repeat('g', 32));
        config()->set('payments.gift_card.code_lookup_key_version', 7);
        $this->configureMethod();
    }

    public function test_code_is_protected_and_authoritative_redeem_captures_once_through_common_settlement(): void
    {
        $this->registerType('gift-auto', 'automatic_only');
        $purchase = $this->purchase('auto');
        $code = 'STEAM-ABCD-1234-EFGH';
        $submission = $this->submit($purchase, 'auto', 'gift-auto', $code);

        $row = DB::table('gift_card_submissions')->where('id', $submission->submissionId)->first();
        self::assertNotNull($row);
        self::assertNotSame($code, $row->encrypted_code);
        self::assertStringNotContainsString($code, (string) $row->encrypted_code);
        self::assertSame(hash_hmac('sha256', $code, str_repeat('g', 32)), $row->code_lookup_hash);
        self::assertNotSame(hash('sha256', $code), $row->code_lookup_hash);
        self::assertSame(7, (int) $row->code_lookup_key_version);
        self::assertStringNotContainsString($code, (string) $row->masked_code);
        self::assertSame('submitted', $row->state);
        self::assertSame('submitted', DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->value('state'));

        $provider = new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, false, true, false, true),
        );
        $provider->put('validate', $this->operationKey($submission->publicId, 'validate'), $this->evidence(
            'validate', 'valid', 'validate-auto', null, $purchase['amount'],
        ));
        $provider->put('redeem', $this->operationKey($submission->publicId, 'redeem'), $this->evidence(
            'redeem', 'redeemed', 'redeem-auto', 'redeem-tx-auto', $purchase['amount'],
        ));

        $processed = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('process-auto'),
        );

        self::assertSame('captured', $processed->state);
        self::assertNotNull($processed->redemptionPublicId);
        self::assertNotNull($processed->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('gift_card_redemptions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'gift_card')->count());
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->value('state'));
        $settlement = DB::table('purchase_settlements')->where('public_id', $processed->purchaseSettlementPublicId)->first();
        self::assertNotNull($settlement);
        self::assertSame(hash('sha256', "fake_gift_card\0redeem-tx-auto"), $settlement->provider_transaction_id);
        self::assertSame($purchase['amount'], (int) $settlement->amount_irr);

        $replay = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('process-auto-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($processed->purchaseSettlementPublicId, $replay->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('gift_card_redemptions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'gift_card')->count());
    }

    public function test_same_code_cannot_be_bound_to_second_purchase(): void
    {
        $this->registerType('gift-unique', 'automatic_only');
        $first = $this->purchase('unique-first');
        $second = $this->purchase('unique-second');
        $code = 'UNIQUE-CARD-0001';

        $this->submit($first, 'unique-first', 'gift-unique', $code);

        try {
            $this->submit($second, 'unique-second', 'gift-unique', $code);
            self::fail('Expected duplicate gift-card code authority to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already bound to another purchase', $exception->getMessage());
        }

        self::assertSame(1, DB::table('gift_card_submissions')->count());
        self::assertSame(1, DB::table('payment_intents')->where('payment_method_code', 'gift_card')->count());
    }

    public function test_validation_only_provider_routes_to_review_and_validity_evidence_cannot_approve_payment(): void
    {
        $this->registerType('gift-review', 'automatic_then_manual');
        $purchase = $this->purchase('review');
        $submission = $this->submit($purchase, 'review', 'gift-review', 'REVIEW-CARD-0001');
        $provider = new FakeGiftCardVerificationProvider(
            'fake_gift_card',
            new GiftCardProviderCapabilities(true, false, false, false, true),
        );
        $validation = $this->evidence('validate', 'valid', 'validate-review', null, $purchase['amount']);
        $provider->put('validate', $this->operationKey($submission->publicId, 'validate'), $validation);

        $pending = $this->app->make(GiftCardPaymentService::class)->process(
            $submission->publicId,
            $provider,
            $this->correlation('process-review'),
        );
        self::assertSame('pending_manual_review', $pending->state);
        self::assertNotNull($pending->reviewPublicId);
        self::assertSame(0, DB::table('gift_card_redemptions')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        try {
            $this->app->make(GiftCardReviewDecisionService::class)->approveRedeemed(
                $pending->reviewPublicId,
                $this->ownerAdministrator(),
                'Validity-only evidence must not settle a purchase.',
                'fake_gift_card',
                $validation,
                $this->correlation('invalid-review-approval'),
            );
            self::fail('Expected validation-only evidence to be rejected for manual approval.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('authoritative redeemed evidence', $exception->getMessage());
        }

        $redeemed = $this->evidence('redeem', 'redeemed', 'manual-redeem', 'manual-redeem-tx', $purchase['amount']);
        $captured = $this->app->make(GiftCardReviewDecisionService::class)->approveRedeemed(
            $pending->reviewPublicId,
            $this->ownerAdministrator(),
            'External redemption evidence is exact and irreversible.',
            'fake_gift_card',
            $redeemed,
            $this->correlation('valid-review-approval'),
        );
        self::assertSame('captured', $captured->state);
        self::assertSame(1, DB::table('gift_card_redemptions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'gift_card')->count());
    }

    public function test_database_rejects_forged_gift_card_settlement_without_redemption(): void
    {
        $this->registerType('gift-db', 'automatic_only');
        $purchase = $this->purchase('db-forge');
        $submission = $this->submit($purchase, 'db-forge', 'gift-db', 'DB-FORGE-CARD-0001');
        $intent = DB::table('payment_intents')->where('public_id', $submission->paymentIntentPublicId)->first();
        self::assertNotNull($intent);
        $eventHash = hash('sha256', 'forged-gift-card-event');
        $providerTransactionId = hash('sha256', "fake_gift_card\0forged-redemption");
        $settledAt = '2026-08-14 14:05:00.000000';

        $providerEventId = (int) DB::table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $intent->id,
            'provider_code' => 'gift_card',
            'provider_event_id' => hash('sha256', 'forged-provider-event'),
            'event_payload_hash' => $eventHash,
            'provider_transaction_id' => $providerTransactionId,
            'evidence_payload_hash' => $eventHash,
            'evidence_authority' => 'authoritative',
            'transaction_status' => 'settled',
            'amount_irr' => $intent->amount_irr,
            'currency' => 'IRR',
            'occurred_at' => $settledAt,
            'settled_at' => $settledAt,
            'safe_evidence' => '{}',
            'created_at' => $settledAt,
        ]);
        $providerTransactionRowId = (int) DB::table('payment_provider_transactions')->insertGetId([
            'payment_intent_id' => $intent->id,
            'provider_event_row_id' => $providerEventId,
            'provider_code' => 'gift_card',
            'provider_transaction_id' => $providerTransactionId,
            'evidence_payload_hash' => $eventHash,
            'transaction_status' => 'settled',
            'amount_irr' => $intent->amount_irr,
            'currency' => 'IRR',
            'occurred_at' => $settledAt,
            'settled_at' => $settledAt,
            'created_at' => $settledAt,
        ]);

        $this->assertQueryRejected(static fn (): bool => DB::table('purchase_settlements')->insert([
            'public_id' => (string) Str::ulid(),
            'payment_intent_id' => $intent->id,
            'provider_transaction_row_id' => $providerTransactionRowId,
            'user_id' => $intent->user_id,
            'source_quote_id' => $intent->source_quote_id,
            'source_quote_public_id' => $intent->source_quote_public_id,
            'provider_code' => 'gift_card',
            'provider_transaction_id' => $providerTransactionId,
            'evidence_payload_hash' => $eventHash,
            'amount_irr' => $intent->amount_irr,
            'currency' => 'IRR',
            'settled_at' => $settledAt,
            'created_at' => $settledAt,
        ]));
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    /** @return array{user_id:int,quote_public_id:string,eligibility_public_id:string,amount:int} */
    private function purchase(string $suffix): array
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'gift.quote.'.$suffix,
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'gift.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
        );

        return [
            'user_id' => $user,
            'quote_public_id' => $quote->quotePublicId,
            'eligibility_public_id' => $decision->publicId,
            'amount' => $quote->amount->amount(),
        ];
    }

    private function submit(array $purchase, string $suffix, string $typeCode, string $code): \App\Modules\Payments\GiftCard\Application\GiftCardSubmissionReceipt
    {
        return $this->app->make(GiftCardSubmissionService::class)->submit(
            'gift.submission.'.$suffix,
            'gift.intent.'.$suffix,
            $purchase['user_id'],
            $purchase['quote_public_id'],
            $purchase['eligibility_public_id'],
            $typeCode,
            $purchase['amount'],
            'IRR',
            'Steam',
            'GLOBAL',
            $code,
            null,
            null,
            null,
            null,
            $this->correlation('submit-'.$suffix),
        );
    }

    private function registerType(string $typeCode, string $verificationMode): void
    {
        $this->app->make(GiftCardTypeService::class)->register(
            $typeCode,
            'Steam Gift Card',
            'Steam',
            'GLOBAL',
            'IRR',
            'code_only',
            $verificationMode,
            null,
            'fake_gift_card',
        );
    }

    private function evidence(
        string $operation,
        string $status,
        string $suffix,
        ?string $transactionId,
        int $amount,
    ): GiftCardProviderEvidence {
        return new GiftCardProviderEvidence(
            $operation,
            'success',
            $status,
            'gift-event-'.$suffix,
            $transactionId,
            $amount,
            'IRR',
            'Steam',
            'GLOBAL',
            $this->clock->value->modify('+2 minutes'),
            hash('sha256', 'gift-evidence:'.$suffix),
            ['source' => 'fake_test'],
        );
    }

    private function configureMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'gift.method.foundation',
            $administratorId,
            'gift_card',
            true,
            false,
            1,
            'Gift-card payment test configuration.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'gift.health.foundation',
            $administratorId,
            'gift_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy gift-card test provider observation.',
            $this->correlation('health'),
        );
    }

    private function operationKey(string $submissionPublicId, string $operation): string
    {
        return hash('sha256', 'gift-card:'.$submissionPublicId.':'.$operation);
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'gift-card-test:'.$suffix);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected gift-card database authority rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}