<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Usdt\Application\Contracts\BlockchainTransactionVerificationProvider;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationRequest;
use App\Modules\Payments\Usdt\Application\UsdtBep20Asset;
use App\Modules\Payments\Usdt\Application\UsdtBlockchainVerificationService;
use App\Modules\Payments\Usdt\Application\UsdtReconciliationService;
use App\Modules\Payments\Usdt\Infrastructure\FakeBlockchainTransactionVerificationProvider;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\UsdtAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class UsdtReconciliationClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class ThrowingUsdtBlockchainProvider implements BlockchainTransactionVerificationProvider
{
    public function code(): string
    {
        return 'throwing_chain';
    }

    public function lookup(UsdtBlockchainVerificationRequest $request): UsdtBlockchainVerificationEvidence
    {
        unset($request);
        throw new RuntimeException('Synthetic chain provider outage.');
    }
}

/** @requirement USDT-003 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 INT-001 INT-002 QUA-004 */
final class UsdtBep20ReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use UsdtBep20TestSupport;

    private UsdtReconciliationClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(UsdtAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new UsdtReconciliationClock(new DateTimeImmutable('2026-08-14T07:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        config()->set('payments.usdt_bep20.chain_id', 56);
        config()->set('payments.usdt_bep20.token_contract', UsdtBep20Asset::TOKEN_CONTRACT);
        config()->set('payments.usdt_bep20.minimum_confirmations', 15);
        $this->configureUsdtBep20Method($this->clock, 'reconciliation');
    }

    public function test_provider_failure_is_durable_and_read_only_retry_can_later_capture(): void
    {
        $setup = $this->prepareUsdtBep20Submission('provider-failure', $this->clock, '0x'.str_repeat('6a', 32));
        $submission = $setup['submission'];

        try {
            $this->app->make(UsdtBlockchainVerificationService::class)->verify(
                $submission->publicId,
                new ThrowingUsdtBlockchainProvider,
                $this->usdtSupportCorrelation('provider-failure-first'),
            );
            self::fail('Expected synthetic USDT chain provider outage.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('provider lookup failed', $exception->getMessage());
        }

        self::assertSame('provider_unavailable', DB::table('usdt_txid_submissions')->where('id', $submission->submissionId)->value('state'));
        self::assertSame(1, DB::table('usdt_reconciliation_findings')->where('finding_type', 'chain_provider_lookup_failed')->count());
        self::assertSame(0, DB::table('usdt_verified_transfers')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
        $provider->put($this->successEvidence(
            $submission->txid,
            $setup['amount_base_units'],
            $setup['transaction_at'],
            20,
            'provider-failure-recovered',
        ));
        $recovered = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->usdtSupportCorrelation('provider-failure-retry'),
        );

        self::assertSame('captured', $recovered->state);
        self::assertSame(1, DB::table('usdt_verified_transfers')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'usdt_bep20')->count());
    }

    public function test_insufficient_confirmations_remain_retryable_without_manual_capture_and_later_converge(): void
    {
        $setup = $this->prepareUsdtBep20Submission('confirmations', $this->clock, '0x'.str_repeat('7b', 32));
        $submission = $setup['submission'];
        $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
        $provider->put($this->successEvidence(
            $submission->txid,
            $setup['amount_base_units'],
            $setup['transaction_at'],
            5,
            'confirmations-low',
        ));

        $pending = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->usdtSupportCorrelation('confirmations-low'),
        );
        self::assertSame('verifying', $pending->state);
        self::assertNull($pending->reviewPublicId);
        self::assertSame(1, DB::table('usdt_reconciliation_findings')->where('finding_type', 'insufficient_confirmations')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());

        $provider->put($this->successEvidence(
            $submission->txid,
            $setup['amount_base_units'],
            $setup['transaction_at'],
            20,
            'confirmations-ready',
        ));
        $captured = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->usdtSupportCorrelation('confirmations-ready'),
        );
        self::assertSame('captured', $captured->state);
        self::assertSame(1, DB::table('usdt_verified_transfers')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'usdt_bep20')->count());
    }

    public function test_post_capture_reversal_creates_critical_finding_without_second_financial_effect(): void
    {
        $setup = $this->prepareUsdtBep20Submission('reversal', $this->clock, '0x'.str_repeat('8c', 32));
        $submission = $setup['submission'];
        $provider = new FakeBlockchainTransactionVerificationProvider('fake_bep20');
        $provider->put($this->successEvidence(
            $submission->txid,
            $setup['amount_base_units'],
            $setup['transaction_at'],
            20,
            'reversal-capture',
        ));
        $captured = $this->app->make(UsdtBlockchainVerificationService::class)->verify(
            $submission->publicId,
            $provider,
            $this->usdtSupportCorrelation('reversal-capture'),
        );
        self::assertSame('captured', $captured->state);

        $provider->put(new UsdtBlockchainVerificationEvidence(
            'rejected',
            'reverted',
            'chain-event-reversal-later',
            $submission->txid,
            'BEP20',
            56,
            '0x'.str_repeat('aa', 20),
            '0x'.str_repeat('bb', 20),
            $setup['amount_base_units'],
            6,
            21,
            12345679,
            $setup['transaction_at'],
            $setup['transaction_at']->modify('+5 minutes'),
            hash('sha256', 'chain-evidence-reversal-later'),
            ['source' => 'fake_reconciliation'],
        ));
        $reconciled = $this->app->make(UsdtReconciliationService::class)->reconcile(
            $submission->publicId,
            $provider,
            $this->usdtSupportCorrelation('reversal-reconcile'),
        );

        self::assertSame('captured', $reconciled->state);
        self::assertSame(1, DB::table('usdt_reconciliation_findings')->where('finding_type', 'post_capture_chain_reversal')->where('severity', 'critical')->count());
        self::assertSame(1, DB::table('usdt_verified_transfers')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'usdt_bep20')->count());
        self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_code', 'usdt_bep20')->count());
    }

    private function successEvidence(string $txid, int $amountBaseUnits, DateTimeImmutable $transactionAt, int $confirmations, string $suffix): UsdtBlockchainVerificationEvidence
    {
        return new UsdtBlockchainVerificationEvidence(
            'success',
            'success',
            'chain-event-'.$suffix,
            $txid,
            'BEP20',
            56,
            '0x'.str_repeat('aa', 20),
            '0x'.str_repeat('bb', 20),
            $amountBaseUnits,
            6,
            $confirmations,
            12345678,
            $transactionAt,
            $transactionAt->modify('+10 seconds'),
            hash('sha256', 'chain-evidence-'.$suffix),
            ['source' => 'fake_test'],
        );
    }
}
