<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PurchasePaymentClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-002 PAY-001 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchasePaymentIntentAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchasePaymentClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new PurchasePaymentClock(new DateTimeImmutable('2026-08-13T01:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_purchase_intent_binds_quote_decision_and_selected_method_and_replays_after_quote_expiry(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $quote = $this->quoteFor($userId, 'primary');
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, 'purchase_gateway', 1);
        $decision = $eligibility->evaluate('eligibility.purchase.000001', $userId, $quote->quotePublicId);

        $service = $this->app->make(PurchasePaymentIntentService::class);
        $receipt = $service->create(
            'purchase.intent.000001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'purchase_gateway',
            $this->correlation('create-primary'),
        );

        self::assertFalse($receipt->replayed);
        self::assertSame($userId, $receipt->userId);
        self::assertSame($quote->quotePublicId, $receipt->sourceQuotePublicId);
        self::assertSame($decision->publicId, $receipt->eligibilityDecisionPublicId);
        self::assertSame('purchase_gateway', $receipt->methodCode);
        self::assertSame($quote->finalPriceIrr, $receipt->amount->amount());
        self::assertSame('IRR', $receipt->amount->currency());

        /** @var object{purpose:string,wallet_account_id:int|string|null,source_quote_id:int|string,source_quote_public_id:string,source_quote_configuration_hash:string,payment_eligibility_decision_id:int|string,payment_eligibility_decision_public_id:string,payment_eligibility_configuration_hash:string,payment_eligibility_method_configuration_hash:string,payment_method_version_id:int|string,payment_method_code:string,payment_method_version:int|string,payment_method_configuration_hash:string,provider_code:string,amount_irr:int|string,currency:string}|null $row */
        $row = DB::table('payment_intents')->where('public_id', $receipt->intentPublicId)->first();
        self::assertNotNull($row);
        self::assertSame('purchase', $row->purpose);
        self::assertNull($row->wallet_account_id);
        self::assertSame($quote->quoteId, (int) $row->source_quote_id);
        self::assertSame($quote->quotePublicId, $row->source_quote_public_id);
        self::assertSame($quote->configurationSnapshotHash, $row->source_quote_configuration_hash);
        self::assertSame($decision->decisionId, (int) $row->payment_eligibility_decision_id);
        self::assertSame($decision->publicId, $row->payment_eligibility_decision_public_id);
        self::assertSame($decision->configurationSnapshotHash, $row->payment_eligibility_configuration_hash);
        self::assertSame('purchase_gateway', $row->payment_method_code);
        self::assertSame('purchase_gateway', $row->provider_code);
        self::assertSame($quote->finalPriceIrr, (int) $row->amount_irr);
        self::assertSame('IRR', $row->currency);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row->payment_eligibility_method_configuration_hash);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row->payment_method_configuration_hash);
        self::assertSame(0, DB::table('ledger_transactions')->count());
        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());

        $this->clock->value = $this->clock->value->modify('+2 hours');
        $replay = $service->create(
            'purchase.intent.000001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'purchase_gateway',
            $this->correlation('replay-after-expiry'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->intentPublicId, $replay->intentPublicId);
        self::assertSame(1, DB::table('payment_intents')->count());
    }

    public function test_creation_key_conflict_and_unselected_method_fail_closed(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $quote = $this->quoteFor($userId, 'conflict');
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, 'alpha_gateway', 1);
        $this->configureHealthyMethod($eligibility, $administratorId, 'beta_gateway', 2);
        $decision = $eligibility->evaluate('eligibility.purchase.conflict.000001', $userId, $quote->quotePublicId);
        $service = $this->app->make(PurchasePaymentIntentService::class);

        $service->create(
            'purchase.intent.conflict.000001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'alpha_gateway',
            $this->correlation('conflict-alpha'),
        );

        $this->assertRuntimeMessage('Payment intent creation key conflict.', fn (): mixed => $service->create(
            'purchase.intent.conflict.000001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'beta_gateway',
            $this->correlation('conflict-beta'),
        ));
        $this->assertDomainMessage('Payment method was not selected by the eligibility decision.', fn (): mixed => $service->create(
            'purchase.intent.unselected.000001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'missing_gateway',
            $this->correlation('missing-method'),
        ));
        self::assertSame(1, DB::table('payment_intents')->count());
    }

    public function test_foreign_and_expired_quotes_fail_without_creating_purchase_intent(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $quote = $this->quoteFor($userId, 'expiry', '+5 minutes');
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, 'secure_gateway', 1);
        $decision = $eligibility->evaluate('eligibility.purchase.expiry.000001', $userId, $quote->quotePublicId);
        $service = $this->app->make(PurchasePaymentIntentService::class);

        $foreignUserId = $this->quoteUser('customer');
        $this->assertDomainMessage('Purchase payment Quote owner does not match user.', fn (): mixed => $service->create(
            'purchase.intent.foreign.000001',
            $foreignUserId,
            $quote->quotePublicId,
            $decision->publicId,
            'secure_gateway',
            $this->correlation('foreign-user'),
        ));

        $this->clock->value = $this->clock->value->modify('+5 minutes');
        $this->assertDomainMessage('Purchase payment requires a current Quote.', fn (): mixed => $service->create(
            'purchase.intent.expired.000001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'secure_gateway',
            $this->correlation('expired-quote'),
        ));
        self::assertSame(0, DB::table('payment_intents')->count());
    }

    public function test_database_guards_reject_forged_cross_user_binding_and_financial_identity_mutation(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $quote = $this->quoteFor($userId, 'database-guard');
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyMethod($eligibility, $administratorId, 'guard_gateway', 1);
        $decision = $eligibility->evaluate('eligibility.purchase.guard.000001', $userId, $quote->quotePublicId);
        $service = $this->app->make(PurchasePaymentIntentService::class);
        $receipt = $service->create(
            'purchase.intent.guard.000001',
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'guard_gateway',
            $this->correlation('guard-create'),
        );

        $this->assertQueryRejected(static fn (): int => DB::table('payment_intents')
            ->where('public_id', $receipt->intentPublicId)
            ->update(['amount_irr' => $quote->finalPriceIrr + 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('payment_intents')
            ->where('public_id', $receipt->intentPublicId)
            ->update(['source_quote_configuration_hash' => hash('sha256', 'tampered')]));

        /** @var object|null $stored */
        $stored = DB::table('payment_intents')->where('public_id', $receipt->intentPublicId)->first();
        self::assertNotNull($stored);
        $forged = (array) $stored;
        unset($forged['id']);
        $forged['public_id'] = (string) Str::ulid();
        $forged['creation_key'] = 'purchase.intent.forged.000001';
        $forged['payload_hash'] = hash('sha256', 'forged-purchase-intent');
        $forged['user_id'] = $this->quoteUser('customer');
        $forged['state'] = 'created';
        $forged['captured_at'] = null;
        $forged['creation_correlation_id'] = $this->correlation('forged-create');

        $this->assertQueryRejected(static fn (): bool => DB::table('payment_intents')->insert($forged));
        self::assertSame(1, DB::table('payment_intents')->count());
    }

    private function configureHealthyMethod(PaymentMethodEligibilityService $service, int $administratorId, string $methodCode, int $priority): void
    {
        $service->configureMethod(
            'eligibility.method.'.$methodCode.'.purchase.000001',
            $administratorId,
            $methodCode,
            true,
            false,
            $priority,
            'Purchase payment test configuration.',
            $this->correlation('method-'.$methodCode),
        );
        $service->recordHealth(
            'eligibility.health.'.$methodCode.'.purchase.000001',
            $administratorId,
            $methodCode,
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy purchase payment observation.',
            $this->correlation('health-'.$methodCode),
        );
    }

    private function quoteFor(int $userId, string $suffix, string $expiry = '+30 minutes'): object
    {
        $offering = $this->quoteOffering();

        return $this->app->make(QuoteService::class)->create(
            'purchase.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify($expiry)),
            $this->correlation('quote-'.$suffix),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-payment:'.$suffix);
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (\DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
