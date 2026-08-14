<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\CardToCard\Application\CardToCardDestinationService;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\CardToCard\Application\CardToCardProviderPollingService;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionPage;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\CardToCard\Infrastructure\FakeBankTransactionVerificationProvider;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReconciliationFixedC2cAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        return $minimumIrr;
    }
}

final class ReconciliationC2cClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}
    public function now(): DateTimeImmutable { return $this->value; }
}

/** @requirement C2C-005 DAT-002 DAT-003 DAT-004 QUA-004 */
final class CardToCardReconciliationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private ReconciliationC2cClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new ReconciliationC2cClock(new DateTimeImmutable('2026-08-14T14:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(CardToCardAdjustmentGenerator::class, new ReconciliationFixedC2cAdjustmentGenerator());
        config()->set('payments.card_to_card.lookup_key', str_repeat('r', 32));

        $administratorId = $this->ownerAdministrator();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod('c2c.reconcile.method', $administratorId, 'card_to_card', true, false, 1, 'Reconciliation method.', $this->correlation('method'));
        $eligibility->recordHealth('c2c.reconcile.health', $administratorId, 'card_to_card', true, $this->clock->value->modify('+30 minutes'), 'Healthy reconciliation provider.', $this->correlation('health'));
        $this->app->make(CardToCardDestinationService::class)->register(
            'reconcile-primary', '4242424242424242', 'Reconciliation Account', true, 1000, 9990, 30, 120, null, 10, 'fake', 'Reconciliation destination.', $this->correlation('destination')
        );
    }

    public function test_reversal_after_capture_creates_critical_immutable_finding_without_second_financial_effect(): void
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'c2c.reconcile.quote',
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote'),
        );
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate('c2c.reconcile.eligibility', $user, $quote->quotePublicId);
        $payment = $this->app->make(CardToCardPaymentService::class)->create(
            'c2c.reconcile.intent', $user, $quote->quotePublicId, $decision->publicId, $this->correlation('payment')
        );

        $occurredAt = $this->clock->value->modify('+2 minutes');
        $provider = new FakeBankTransactionVerificationProvider('fake');
        $provider->put(null, new BankTransactionPage([
            new BankTransactionObservation(
                'reconcile-tx-1', 'reconcile-event-settled', '4242424242424242', $payment->payableAmountIrr, 'settled', $occurredAt,
                null, null, 'reconcile-reference', hash('sha256', 'reconcile-settled')
            ),
        ], 'after-settlement'));

        $first = $this->app->make(CardToCardProviderPollingService::class)->poll($provider, $this->correlation('poll-settled'));
        self::assertSame(1, $first['captured']);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('c2c_reconciliation_findings')->count());

        $this->clock->value = $this->clock->value->modify('+5 minutes');
        $provider->put('after-settlement', new BankTransactionPage([
            new BankTransactionObservation(
                'reconcile-tx-1', 'reconcile-event-reversed', '4242424242424242', $payment->payableAmountIrr, 'reversed', $occurredAt,
                null, null, 'reconcile-reference', hash('sha256', 'reconcile-reversed')
            ),
        ], 'after-reversal'));

        $second = $this->app->make(CardToCardProviderPollingService::class)->poll($provider, $this->correlation('poll-reversed'));
        self::assertSame(1, $second['ingested']);
        self::assertSame(0, $second['matched']);
        self::assertSame(0, $second['captured']);
        self::assertSame(1, DB::table('purchase_settlements')->count());
        self::assertSame('reversed', DB::table('c2c_bank_transactions')->where('provider_transaction_id', 'reconcile-tx-1')->value('status'));
        self::assertSame(1, DB::table('c2c_reconciliation_findings')->where('finding_type', 'captured_transaction_reversed')->where('severity', 'critical')->count());

        $findingId = DB::table('c2c_reconciliation_findings')->where('finding_type', 'captured_transaction_reversed')->value('id');
        try {
            DB::table('c2c_reconciliation_findings')->where('id', $findingId)->update(['severity' => 'warning']);
            self::fail('Expected immutable reconciliation finding update to fail.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertTrue(true);
        }
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'c2c-reconcile:'.$suffix);
    }
}
