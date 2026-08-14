<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\UsdtAmountQuoteService;
use App\Modules\Payments\Usdt\Application\UsdtBep20Asset;
use App\Modules\Payments\Usdt\Application\UsdtBlockchainVerificationService;
use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtDestinationWalletService;
use App\Modules\Payments\Usdt\Application\UsdtManualReviewDecisionService;
use App\Modules\Payments\Usdt\Application\UsdtPaymentAuthorityReceipt;
use App\Modules\Payments\Usdt\Application\UsdtPaymentAuthorityService;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Application\UsdtTokenAmount;
use App\Modules\Payments\Usdt\Application\UsdtTxidSubmissionReceipt;
use App\Modules\Payments\Usdt\Application\UsdtTxidSubmissionService;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Modules\Payments\Usdt\Infrastructure\FakeBlockchainTransactionVerificationProvider;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\UsdtAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class UsdtPaymentFlowClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class UsdtPaymentRateProvider implements UsdtRateProvider
{
    public function __construct(
        private readonly string $providerCode,
        private readonly string $rateIrr,
        private readonly DateTimeImmutable $fetchedAt,
    ) {}

    public function code(): string
    {
        return $this->providerCode;
    }

    public function fetch(UsdtRateSide $side): UsdtRate
    {
        return new UsdtRate(
            $this->providerCode,
            $this->rateIrr,
            $this->fetchedAt,
            hash('sha256', $this->providerCode."\0".$this->rateIrr."\0".$side->value),
        );
    }
}

/** @requirement USDT-003 PAY-002 PAY-003 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
final class UsdtBep20PaymentFlowTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private UsdtPaymentFlowClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(UsdtAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new UsdtPaymentFlowClock(new DateTimeImmutable('2026-08-14T06:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        config()->set('payments.usdt_bep20.chain_id', 56);
        config()->set('payments.usdt_bep20.token_contract', UsdtBep20Asset::TOKEN_CONTRACT);
        config()->set('payments.usdt_bep20.minimum_confirmations', 15);
        $this->configurePaymentMethod();
    }

    public function test_exact_confirmed_bep20_transfer_captures_once_through_common_purchase_settlement(): void
    {
        $payment = $this->preparedPayment('exact');
        $submission = $this->submit($payment, 'exact', '0x'.str_repeat('1a', 32));
        $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
        $provider->put($this->evidence($submission->txid, $payment['expected_base_units'], $this->clock->value->modify('+60 seconds'), 20, 'exact'));

        $processed = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->correlation('verify-exact'),
        );

        self::assertSame('captured', $processed->state);
        self::assertNotNull($processed->verifiedTransferPublicId);
        self::assertNotNull($processed->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('usdt_verified_transfers')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'usdt_bep20')->count());
        $settlement = DB::table('purchase_settlements')->where('public_id', $processed->purchaseSettlementPublicId)->first();
        self::assertNotNull($settlement);
        self::assertSame(hash('sha256', "BEP20\0".$submission->txid), $settlement->provider_transaction_id);
        self::assertSame($payment['amount_irr'], (int) $settlement->amount_irr);

        $replay = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->correlation('verify-exact-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($processed->purchaseSettlementPublicId, $replay->purchaseSettlementPublicId);
        self::assertSame(1, DB::table('usdt_verified_transfers')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'usdt_bep20')->count());
    }

    public function test_late_exact_transfer_requires_authorized_manual_review_and_receipt_cannot_replace_chain_evidence(): void
    {
        $payment = $this->preparedPayment('late');
        $submission = $this->submit($payment, 'late', '0x'.str_repeat('2b', 32), 'private://receipt/late', hash('sha256', 'receipt-late'));
        $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
        $lateEvidence = $this->evidence($submission->txid, $payment['expected_base_units'], $this->clock->value->modify('+180 seconds'), 20, 'late');
        $provider->put($lateEvidence);

        $pending = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->correlation('verify-late'),
        );
        self::assertSame('pending_manual_review', $pending->state);
        self::assertNotNull($pending->reviewPublicId);
        self::assertSame(0, DB::table('usdt_verified_transfers')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $unauthorized = $this->nonOwnerAdministrator();
        try {
            $this->app->make(UsdtManualReviewDecisionService::class)->approveVerified(
                $pending->reviewPublicId,
                $unauthorized,
                'Attempt without sensitive approval permission.',
                'fake_bep20',
                $lateEvidence,
                $this->correlation('late-unauthorized'),
            );
            self::fail('Expected default-deny USDT manual approval authorization.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        self::assertSame('pending', DB::table('usdt_manual_reviews')->where('public_id', $pending->reviewPublicId)->value('state'));

        $captured = $this->app->make(UsdtManualReviewDecisionService::class)->approveVerified(
            $pending->reviewPublicId,
            $this->ownerAdministrator(),
            'Exact authoritative transfer arrived after the locked quote window.',
            'fake_bep20',
            $lateEvidence,
            $this->correlation('late-approved'),
        );
        self::assertSame('captured', $captured->state);
        self::assertSame(1, DB::table('usdt_verified_transfers')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'usdt_bep20')->count());
    }

    public function test_underpayment_enters_review_but_cannot_be_approved_as_payment(): void
    {
        $payment = $this->preparedPayment('underpaid');
        $submission = $this->submit($payment, 'underpaid', '0x'.str_repeat('3c', 32));
        $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
        $underpaid = $this->evidence($submission->txid, bcsub($payment['expected_base_units'], '1', 0), $this->clock->value->modify('+60 seconds'), 20, 'underpaid');
        $provider->put($underpaid);

        $pending = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->correlation('verify-underpaid'),
        );
        self::assertSame('pending_manual_review', $pending->state);
        self::assertSame(0, DB::table('purchase_settlements')->count());

        try {
            $this->app->make(UsdtManualReviewDecisionService::class)->approveVerified(
                $pending->reviewPublicId ?? throw new RuntimeException('Missing review ID.'),
                $this->ownerAdministrator(),
                'Underpayment must not be manually promoted to exact payment.',
                'fake_bep20',
                $underpaid,
                $this->correlation('underpaid-approval'),
            );
            self::fail('Expected exact-chain manual approval guard.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('exact authoritative BEP20', $exception->getMessage());
        }
        self::assertSame(0, DB::table('usdt_verified_transfers')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    public function test_txid_is_globally_unique_across_prepared_purchase_authorities(): void
    {
        $first = $this->preparedPayment('txid-first');
        $second = $this->preparedPayment('txid-second');
        $txid = '0x'.str_repeat('4d', 32);
        $this->submit($first, 'txid-first', strtoupper($txid));

        try {
            $this->submit($second, 'txid-second', $txid);
            self::fail('Expected globally duplicate USDT TXID to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already bound', $exception->getMessage());
        }
        self::assertSame(1, DB::table('usdt_txid_submissions')->count());
    }

    public function test_database_rejects_direct_usdt_settlement_without_verified_transfer(): void
    {
        $payment = $this->preparedPayment('db-forge');
        $submission = $this->submit($payment, 'db-forge', '0x'.str_repeat('5e', 32));
        $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
        $provider->put(new UsdtBlockchainVerificationEvidence(
            'pending', 'pending', 'pending-db-forge', $submission->txid,
            null, null, null, null, null, null, 0, null, null,
            $this->clock->value->modify('+30 seconds'), hash('sha256', 'pending-db-forge'), ['source' => 'fake_test'],
        ));
        $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->correlation('verify-db-forge'),
        );

        $intent = DB::table('payment_intents')->where('public_id', $payment['authority']->paymentIntentPublicId)->first();
        self::assertNotNull($intent);
        $commonTx = hash('sha256', "BEP20\0".$submission->txid);
        $evidenceHash = hash('sha256', 'forged-common-evidence');
        $settledAt = '2026-08-14 06:01:00.000000';
        $eventRowId = (int) DB::table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $intent->id,
            'provider_code' => 'usdt_bep20',
            'provider_event_id' => hash('sha256', 'forged-common-event'),
            'event_payload_hash' => $evidenceHash,
            'provider_transaction_id' => $commonTx,
            'evidence_payload_hash' => $evidenceHash,
            'evidence_authority' => 'authoritative',
            'transaction_status' => 'settled',
            'amount_irr' => $intent->amount_irr,
            'currency' => 'IRR',
            'occurred_at' => $settledAt,
            'settled_at' => $settledAt,
            'safe_evidence' => '{}',
            'created_at' => $settledAt,
        ]);
        $transactionRowId = (int) DB::table('payment_provider_transactions')->insertGetId([
            'payment_intent_id' => $intent->id,
            'provider_event_row_id' => $eventRowId,
            'provider_code' => 'usdt_bep20',
            'provider_transaction_id' => $commonTx,
            'evidence_payload_hash' => $evidenceHash,
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
            'provider_transaction_row_id' => $transactionRowId,
            'user_id' => $intent->user_id,
            'source_quote_id' => $intent->source_quote_id,
            'source_quote_public_id' => $intent->source_quote_public_id,
            'provider_code' => 'usdt_bep20',
            'provider_transaction_id' => $commonTx,
            'evidence_payload_hash' => $evidenceHash,
            'amount_irr' => $intent->amount_irr,
            'currency' => 'IRR',
            'settled_at' => $settledAt,
            'created_at' => $settledAt,
        ]));
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    /** @return array{authority:UsdtPaymentAuthorityReceipt,amount_irr:int,expected_base_units:int} */
    private function preparedPayment(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $source = $this->app->make(QuoteService::class)->create(
            'usdt.payment.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('source-'.$suffix),
        );
        $destination = $this->app->make(UsdtDestinationWalletService::class)->configure(
            'usdt.payment.wallet.'.$suffix,
            $this->ownerAdministrator(),
            'primary',
            '0x'.str_repeat('bb', 20),
            true,
            'USDT BEP20 payment test destination.',
            $this->correlation('wallet-'.$suffix),
        );
        self::assertSame('BEP20', $destination->network);

        $amountQuote = $this->amountQuoteService()->create('usdt.payment.amount.'.$suffix, $source->quotePublicId);
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'usdt.payment.eligibility.'.$suffix,
            $userId,
            $source->quotePublicId,
        );
        $authority = $this->app->make(UsdtPaymentAuthorityService::class)->prepare(
            'usdt.payment.authority.'.$suffix,
            'usdt.payment.intent.'.$suffix,
            $userId,
            $source->quotePublicId,
            $eligibility->publicId,
            $amountQuote->publicId,
            $this->correlation('authority-'.$suffix),
        );

        return [
            'authority' => $authority,
            'amount_irr' => $source->finalPriceIrr,
            'expected_base_units' => UsdtTokenAmount::toBaseUnits($amountQuote->exactUsdt, UsdtBep20Asset::TOKEN_DECIMALS),
        ];
    }

    private function submit(array $payment, string $suffix, string $txid, ?string $privateEvidence = null, ?string $contentHash = null): UsdtTxidSubmissionReceipt
    {
        return $this->app->make(UsdtTxidSubmissionService::class)->submit(
            'usdt.payment.txid.'.$suffix,
            $payment['authority']->publicId,
            $payment['authority']->userId,
            $txid,
            $privateEvidence,
            $contentHash,
            $this->correlation('txid-'.$suffix),
        );
    }

    private function amountQuoteService(): UsdtAmountQuoteService
    {
        $primary = new UsdtPaymentRateProvider('nobitex', '1000000', $this->clock->value);
        $secondary = new UsdtPaymentRateProvider('secondary', '1005000', $this->clock->value);
        $policy = new UsdtRatePolicy(['nobitex', 'secondary'], UsdtRateSide::Buy, 120, '100000', '10000000', 500, false, 3, 60);
        $resolver = new UsdtRateResolver(
            [$primary, $secondary],
            $policy,
            new UsdtCircuitBreaker(new Repository(new ArrayStore), $this->clock, 3, 60),
            $this->clock,
        );

        return new UsdtAmountQuoteService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(QuoteService::class),
            $this->app->make(UsdtDestinationWalletService::class),
            $resolver,
            $this->clock,
            0,
            6,
            120,
            120,
            'primary',
        );
    }

    private function evidence(string $txid, string $amountBaseUnits, DateTimeImmutable $transactionAt, int $confirmations, string $suffix): UsdtBlockchainVerificationEvidence
    {
        return new UsdtBlockchainVerificationEvidence(
            'success',
            'success',
            'chain-event-'.$suffix,
            strtolower($txid),
            'BEP20',
            UsdtBep20Asset::CHAIN_ID,
            UsdtBep20Asset::TOKEN_CONTRACT,
            '0x'.str_repeat('bb', 20),
            $amountBaseUnits,
            UsdtBep20Asset::TOKEN_DECIMALS,
            $confirmations,
            12345678,
            $transactionAt,
            $transactionAt->modify('+10 seconds'),
            hash('sha256', 'chain-evidence-'.$suffix),
            ['source' => 'fake_test'],
        );
    }

    private function configurePaymentMethod(): void
    {
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $owner = $this->ownerAdministrator();
        $eligibility->configureMethod(
            'usdt.payment.method.foundation',
            $owner,
            'usdt_bep20',
            true,
            false,
            1,
            'USDT BEP20 payment test method.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'usdt.payment.health.foundation',
            $owner,
            'usdt_bep20',
            true,
            $this->clock->value->modify('+20 minutes'),
            'USDT BEP20 test verification provider healthy.',
            $this->correlation('health'),
        );
    }

    private function nonOwnerAdministrator(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->quoteUser('customer'),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'usdt-bep20-payment:'.$suffix);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected USDT database authority rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
