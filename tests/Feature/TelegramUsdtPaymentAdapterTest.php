<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Usdt\Application\UsdtAmountQuoteService;
use App\Modules\Payments\Usdt\Application\UsdtBep20Asset;
use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtDestinationWalletService;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseUsdtPayment;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class TelegramUsdtAdapterClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final readonly class TelegramUsdtAdapterRateProvider implements UsdtRateProvider
{
    public function __construct(
        private string $providerCode,
        private string $rateIrr,
        private DateTimeImmutable $fetchedAt,
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

/** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 USDT-001 USDT-002 USDT-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class TelegramUsdtPaymentAdapterTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private TelegramUsdtAdapterClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(UsdtAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);

        $this->clock = new TelegramUsdtAdapterClock(new DateTimeImmutable('2026-09-07T03:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        DB::statement('SET timestamp = '.$this->clock->value->getTimestamp());
        $this->beforeApplicationDestroyed(static function (): void {
            DB::statement('SET timestamp = DEFAULT');
        });
        config()->set('payments.usdt_bep20.chain_id', 56);
        config()->set('payments.usdt_bep20.token_contract', UsdtBep20Asset::TOKEN_CONTRACT);
        config()->set('payments.usdt_bep20.minimum_confirmations', 15);

        $primary = new TelegramUsdtAdapterRateProvider('nobitex', '1000000', $this->clock->value);
        $secondary = new TelegramUsdtAdapterRateProvider('secondary', '1005000', $this->clock->value);
        $policy = new UsdtRatePolicy(['nobitex', 'secondary'], UsdtRateSide::Buy, 120, '100000', '10000000', 500, false, 3, 60);
        $rates = new UsdtRateResolver(
            [$primary, $secondary],
            $policy,
            new UsdtCircuitBreaker(new Repository(new ArrayStore), $this->clock, 3, 60),
            $this->clock,
        );
        $this->app->instance(UsdtRateResolver::class, $rates);
        $this->app->instance(UsdtAmountQuoteService::class, new UsdtAmountQuoteService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(QuoteService::class),
            $this->app->make(UsdtDestinationWalletService::class),
            $rates,
            $this->clock,
        ));

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $owner = $this->ownerAdministrator();
        $eligibility->configureMethod(
            'telegram.usdt.adapter.method',
            $owner,
            'usdt_bep20',
            true,
            false,
            1,
            'Telegram USDT adapter test method.',
            $this->correlation('method'),
        );
        $eligibility->recordHealth(
            'telegram.usdt.adapter.health',
            $owner,
            'usdt_bep20',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Telegram USDT adapter test provider healthy.',
            $this->correlation('health'),
        );
        $this->app->make(UsdtDestinationWalletService::class)->configure(
            'telegram.usdt.adapter.wallet',
            $owner,
            'primary',
            '0x'.str_repeat('bb', 20),
            true,
            'Telegram USDT adapter test destination.',
            $this->correlation('wallet'),
        );
    }

    public function test_initiation_and_txid_submission_replay_create_one_pending_payment_effect_and_no_settlement_or_provisioning(): void
    {
        $checkout = $this->checkout('replay');
        $adapter = $this->app->make(TelegramCustomerPurchaseUsdtPayment::class);
        $initOperation = hash('sha256', 'telegram-usdt-adapter-init');

        $first = $adapter->prepareForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order_public_id'],
            $checkout['quote_public_id'],
            $checkout['quote_hash'],
            $checkout['decision_public_id'],
            $checkout['decision_hash'],
            $initOperation,
        );
        $replay = $adapter->prepareForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order_public_id'],
            $checkout['quote_public_id'],
            $checkout['quote_hash'],
            $checkout['decision_public_id'],
            $checkout['decision_hash'],
            $initOperation,
        );

        self::assertSame($first->authorityPublicId, $replay->authorityPublicId);
        self::assertSame($first->paymentIntentPublicId, $replay->paymentIntentPublicId);
        self::assertSame($first->amountQuotePublicId, $replay->amountQuotePublicId);
        self::assertSame('BEP20', $first->network);
        self::assertSame('0x'.str_repeat('bb', 20), $first->destinationAddress);
        self::assertSame('1.250000', $first->exactUsdt);
        self::assertSame(1, DB::table('usdt_amount_quotes')->count());
        self::assertSame(1, DB::table('usdt_payment_authorities')->count());
        self::assertSame(1, DB::table('payment_intents')->where('provider_code', 'usdt_bep20')->count());

        $txid = '0x'.str_repeat('3c', 32);
        $submitOperation = hash('sha256', 'telegram-usdt-adapter-submit');
        $submitted = $adapter->submitTxidForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order_public_id'],
            $checkout['quote_public_id'],
            $checkout['quote_hash'],
            $checkout['decision_public_id'],
            $checkout['decision_hash'],
            $first->authorityPublicId,
            strtoupper($txid),
            $submitOperation,
        );
        $submittedReplay = $adapter->submitTxidForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order_public_id'],
            $checkout['quote_public_id'],
            $checkout['quote_hash'],
            $checkout['decision_public_id'],
            $checkout['decision_hash'],
            $first->authorityPublicId,
            $txid,
            $submitOperation,
        );

        self::assertSame($submitted->submissionPublicId, $submittedReplay->submissionPublicId);
        self::assertSame($txid, $submitted->txid);
        self::assertSame('submitted', $submitted->state);
        self::assertTrue($submittedReplay->replayed);

        try {
            $adapter->submitTxidForSelf(
                $checkout['user_id'],
                $checkout['user_id'],
                $checkout['order_public_id'],
                $checkout['quote_public_id'],
                $checkout['quote_hash'],
                $checkout['decision_public_id'],
                $checkout['decision_hash'],
                $first->authorityPublicId,
                '0x'.str_repeat('7a', 32),
                hash('sha256', 'telegram-usdt-adapter-competing-txid'),
            );
            self::fail('Expected a competing Telegram USDT TXID to fail closed.');
        } catch (RuntimeException) {
        }

        try {
            $adapter->prepareForSelf(
                $checkout['user_id'],
                $checkout['user_id'],
                $checkout['order_public_id'],
                $checkout['quote_public_id'],
                $checkout['quote_hash'],
                $checkout['decision_public_id'],
                $checkout['decision_hash'],
                hash('sha256', 'telegram-usdt-adapter-reinit-after-submit'),
            );
            self::fail('Expected Telegram USDT initiation after accepted TXID submission to fail closed.');
        } catch (AuthorizationException) {
        }

        self::assertSame(1, DB::table('usdt_txid_submissions')->count());
        self::assertSame('submitted', DB::table('payment_intents')->where('public_id', $first->paymentIntentPublicId)->value('state'));
        self::assertSame(0, DB::table('usdt_verified_transfers')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
    }

    public function test_cross_actor_stale_identity_expired_quote_and_invalid_txid_fail_closed_without_second_payment_effect(): void
    {
        $checkout = $this->checkout('guards');
        $adapter = $this->app->make(TelegramCustomerPurchaseUsdtPayment::class);
        $initOperation = hash('sha256', 'telegram-usdt-adapter-guards-init');
        $instructions = $adapter->prepareForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order_public_id'],
            $checkout['quote_public_id'],
            $checkout['quote_hash'],
            $checkout['decision_public_id'],
            $checkout['decision_hash'],
            $initOperation,
        );

        try {
            $adapter->submitTxidForSelf(
                $checkout['user_id'] + 1,
                $checkout['user_id'],
                $checkout['order_public_id'],
                $checkout['quote_public_id'],
                $checkout['quote_hash'],
                $checkout['decision_public_id'],
                $checkout['decision_hash'],
                $instructions->authorityPublicId,
                '0x'.str_repeat('4d', 32),
                hash('sha256', 'cross-actor'),
            );
            self::fail('Expected cross-actor Telegram USDT rejection.');
        } catch (AuthorizationException) {
        }

        try {
            $adapter->submitTxidForSelf(
                $checkout['user_id'],
                $checkout['user_id'],
                $checkout['order_public_id'],
                $checkout['quote_public_id'],
                $checkout['quote_hash'],
                $checkout['decision_public_id'],
                str_repeat('f', 64),
                $instructions->authorityPublicId,
                '0x'.str_repeat('5e', 32),
                hash('sha256', 'stale-decision'),
            );
            self::fail('Expected stale PAY-001 Telegram USDT rejection.');
        } catch (AuthorizationException) {
        }

        try {
            $adapter->submitTxidForSelf(
                $checkout['user_id'],
                $checkout['user_id'],
                $checkout['order_public_id'],
                $checkout['quote_public_id'],
                $checkout['quote_hash'],
                $checkout['decision_public_id'],
                $checkout['decision_hash'],
                $instructions->authorityPublicId,
                'not-a-txid',
                hash('sha256', 'invalid-txid'),
            );
            self::fail('Expected invalid Telegram USDT TXID rejection.');
        } catch (InvalidArgumentException|DomainException) {
        }

        $this->clock->value = $this->clock->value->modify('+31 minutes');
        try {
            $adapter->submitTxidForSelf(
                $checkout['user_id'],
                $checkout['user_id'],
                $checkout['order_public_id'],
                $checkout['quote_public_id'],
                $checkout['quote_hash'],
                $checkout['decision_public_id'],
                $checkout['decision_hash'],
                $instructions->authorityPublicId,
                '0x'.str_repeat('6f', 32),
                hash('sha256', 'expired-quote'),
            );
            self::fail('Expected expired Quote Telegram USDT rejection.');
        } catch (AuthorizationException) {
        }

        self::assertSame(0, DB::table('usdt_txid_submissions')->count());
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $instructions->paymentIntentPublicId)->value('state'));
        self::assertSame(1, DB::table('usdt_payment_authorities')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
    }

    public function test_already_won_order_rejects_usdt_txid_without_competing_submission_effect(): void
    {
        $this->configureWinningMethod();
        $checkout = $this->checkout('won-order');
        $adapter = $this->app->make(TelegramCustomerPurchaseUsdtPayment::class);
        $instructions = $adapter->prepareForSelf(
            $checkout['user_id'],
            $checkout['user_id'],
            $checkout['order_public_id'],
            $checkout['quote_public_id'],
            $checkout['quote_hash'],
            $checkout['decision_public_id'],
            $checkout['decision_hash'],
            hash('sha256', 'telegram-usdt-adapter-won-init'),
        );
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $instructions->paymentIntentPublicId)->value('state'));

        $winner = $this->app->make(PurchasePaymentIntentService::class)->create(
            'telegram.usdt.adapter.winner.intent',
            $checkout['user_id'],
            $checkout['quote_public_id'],
            $checkout['decision_public_id'],
            'winner_gateway',
            $this->correlation('winner-intent'),
        );
        DB::table('payment_intents')->where('public_id', $winner->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->clock->value->format('Y-m-d H:i:s.u'),
        ]);
        $settlement = $this->app->make(PurchaseSettlementService::class)->capture(
            $winner->intentPublicId,
            'winner_gateway',
            new VerifiedPaymentEvent(
                'evt-telegram-usdt-winner',
                hash('sha256', 'telegram-usdt-winner-event'),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'txn-telegram-usdt-winner',
                    'evt-telegram-usdt-winner',
                    Money::irr($winner->amount->amount()),
                    $this->clock->value,
                    $this->clock->value,
                    hash('sha256', 'telegram-usdt-winner-evidence'),
                    ['provider_reference' => 'txn-telegram-usdt-winner'],
                ),
            ),
            $this->correlation('winner-settlement'),
        );
        self::assertNotNull($settlement->settlementPublicId);
        $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->correlation('winner-order'),
        );
        self::assertSame('paid', DB::table('orders')->where('public_id', $checkout['order_public_id'])->value('state'));

        $before = [
            'submissions' => DB::table('usdt_txid_submissions')->count(),
            'authorities' => DB::table('usdt_payment_authorities')->count(),
            'usdt_intents' => DB::table('payment_intents')->where('provider_code', 'usdt_bep20')->count(),
            'settlements' => DB::table('purchase_settlements')->count(),
        ];
        try {
            $adapter->submitTxidForSelf(
                $checkout['user_id'],
                $checkout['user_id'],
                $checkout['order_public_id'],
                $checkout['quote_public_id'],
                $checkout['quote_hash'],
                $checkout['decision_public_id'],
                $checkout['decision_hash'],
                $instructions->authorityPublicId,
                '0x'.str_repeat('8b', 32),
                hash('sha256', 'telegram-usdt-adapter-won-submit'),
            );
            self::fail('Expected the already-won Order to reject a competing USDT TXID.');
        } catch (AuthorizationException) {
        }

        self::assertSame($before, [
            'submissions' => DB::table('usdt_txid_submissions')->count(),
            'authorities' => DB::table('usdt_payment_authorities')->count(),
            'usdt_intents' => DB::table('payment_intents')->where('provider_code', 'usdt_bep20')->count(),
            'settlements' => DB::table('purchase_settlements')->count(),
        ]);
        self::assertSame('paid', DB::table('orders')->where('public_id', $checkout['order_public_id'])->value('state'));
    }

    private function configureWinningMethod(): void
    {
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $owner = $this->ownerAdministrator();
        $eligibility->configureMethod(
            'telegram.usdt.adapter.winner.method',
            $owner,
            'winner_gateway',
            true,
            false,
            2,
            'Competing authoritative winner for Telegram USDT Order regression.',
            $this->correlation('winner-method'),
        );
        $eligibility->recordHealth(
            'telegram.usdt.adapter.winner.health',
            $owner,
            'winner_gateway',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Healthy competing winner for Telegram USDT Order regression.',
            $this->correlation('winner-health'),
        );
    }

    /**
     * @return array{user_id:int,order_public_id:string,quote_public_id:string,quote_hash:string,decision_public_id:string,decision_hash:string}
     */
    private function checkout(string $suffix): array
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_250_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'telegram.usdt.adapter.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $decisionKey = 'telegram-purchase-payment-methods:'.strtoupper((string) Str::ulid());
        $decision = $this->app->make(TelegramCustomerPurchasePaymentMethods::class)->discoverForSelf(
            $userId,
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $decisionKey,
        );
        self::assertContains('usdt_bep20', $decision->methodCodes);
        $order = $this->app->make(TelegramCustomerPurchaseOrder::class)->openForSelf(
            $userId,
            $userId,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
            $this->correlation('order-'.$suffix),
        );

        return [
            'user_id' => $userId,
            'order_public_id' => $order->orderPublicId,
            'quote_public_id' => $quote->quotePublicId,
            'quote_hash' => $quote->configurationSnapshotHash,
            'decision_public_id' => $decision->decisionPublicId,
            'decision_hash' => $decision->configurationSnapshotHash,
        ];
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'telegram-usdt-adapter:'.$suffix);
    }
}
