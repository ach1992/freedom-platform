<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class MutableQuoteClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
final class QuotePricingSnapshotTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private MutableQuoteClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->clock = new MutableQuoteClock(new DateTimeImmutable('2026-08-08T12:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_quote_snapshots_integer_irr_base_price_and_exact_replays_without_financial_effect(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $service = $this->app->make(QuoteService::class);
        $pricing = $this->input();

        $quote = $service->create('quote.base.000001', $userId, $offering['id'], $pricing, $this->correlation('base-create'));

        self::assertSame(1_000_000, $quote->basePriceIrr);
        self::assertSame(1_000_000, $quote->effectivePriceIrr);
        self::assertSame(0, $quote->discountIrr);
        self::assertSame(1_000_000, $quote->finalPriceIrr);
        self::assertSame('IRR', $quote->currency);
        self::assertSame(QuoteOverrideSource::None, $quote->overrideSource);
        self::assertNull($quote->agentPricing);
        self::assertFalse($quote->replayed);
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());

        /** @var string $snapshotJson */
        $snapshotJson = DB::table('quotes')->where('id', $quote->quoteId)->value('configuration_snapshot');
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('buy-002-v1', $snapshot['formula_version']);
        self::assertSame('draft', $snapshot['offering_state']);
        self::assertSame('hidden', $snapshot['offering_visibility']);
        self::assertArrayNotHasKey('agent_pricing', $snapshot);
        self::assertSame($quote->configurationSnapshotHash, hash('sha256', $snapshotJson));

        $replay = $service->create('quote.base.000001', $userId, $offering['id'], $pricing, $this->correlation('base-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($quote->quoteId, $replay->quoteId);
        self::assertSame($quote->quotePublicId, $replay->quotePublicId);
        self::assertSame($quote->configurationSnapshotHash, $replay->configurationSnapshotHash);
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_same_quote_key_with_changed_pricing_or_validity_conflicts_without_overwrite(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $service = $this->app->make(QuoteService::class);
        $original = $this->input();
        $service->create('quote.conflict.000001', $userId, $offering['id'], $original, $this->correlation('conflict-create'));

        $changed = new QuotePricingInput(
            QuoteOverrideSource::Account,
            'account.manual',
            900_000,
            null,
            0,
            $this->clock->value->modify('+30 minutes'),
        );
        $this->assertRuntimeMessage('Quote key conflict.', fn (): mixed => $service->create(
            'quote.conflict.000001',
            $userId,
            $offering['id'],
            $changed,
            $this->correlation('conflict-pricing'),
        ));

        $changedValidity = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            null,
            0,
            $this->clock->value->modify('+31 minutes'),
        );
        $this->assertRuntimeMessage('Quote key conflict.', fn (): mixed => $service->create(
            'quote.conflict.000001',
            $userId,
            $offering['id'],
            $changedValidity,
            $this->correlation('conflict-validity'),
        ));
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1_000_000, (int) DB::table('quotes')->value('final_price_irr'));
    }

    public function test_explicit_override_precedes_discount_and_invalid_discount_fails_closed(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(
            QuoteOverrideSource::Account,
            'account.vip-price',
            900_000,
            'discount.manual-100k',
            100_000,
            $this->clock->value->modify('+30 minutes'),
        );

        $quote = $service->create('quote.override.000001', $userId, $offering['id'], $pricing, $this->correlation('override-create'));
        self::assertSame(1_000_000, $quote->basePriceIrr);
        self::assertSame(900_000, $quote->overridePriceIrr);
        self::assertSame(900_000, $quote->effectivePriceIrr);
        self::assertSame(100_000, $quote->discountIrr);
        self::assertSame(800_000, $quote->finalPriceIrr);
        self::assertSame('account.vip-price', $quote->overrideReferenceCode);
        self::assertSame('discount.manual-100k', $quote->discountReferenceCode);
        self::assertNull($quote->agentPricing);

        $tooLarge = new QuotePricingInput(
            QuoteOverrideSource::Account,
            'account.vip-price',
            90_000,
            'discount.too-large',
            90_001,
            $this->clock->value->modify('+30 minutes'),
        );
        $this->assertDomainMessage('Quote discount cannot exceed the effective price.', fn (): mixed => $service->create(
            'quote.override.000002',
            $userId,
            $offering['id'],
            $tooLarge,
            $this->correlation('override-too-large'),
        ));
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_discount_requires_offering_eligibility_and_pricing_input_rejects_invalid_shapes(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering(500_000, false);
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            'discount.not-eligible',
            1,
            $this->clock->value->modify('+10 minutes'),
        );
        $this->assertDomainMessage('Plan offering does not allow a quote discount.', fn (): mixed => $service->create(
            'quote.discount.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('discount-not-eligible'),
        ));

        $this->assertInvalidArgumentMessage(
            'Quote discount must be non-negative integer IRR.',
            fn (): mixed => new QuotePricingInput(QuoteOverrideSource::None, null, null, null, -1, $this->clock->value->modify('+10 minutes')),
        );
        $this->assertInvalidArgumentMessage(
            'Quote without an override cannot contain override data.',
            fn (): mixed => new QuotePricingInput(QuoteOverrideSource::None, 'unexpected', 1, null, 0, $this->clock->value->modify('+10 minutes')),
        );
        self::assertSame(0, DB::table('quotes')->count());
    }

    public function test_current_tier_reference_remains_required_and_legacy_agent_override_is_rejected(): void
    {
        $offering = $this->quoteOffering();
        $service = $this->app->make(QuoteService::class);
        $customerId = $this->quoteUser('customer');
        $normalTierId = (int) DB::table('customer_tiers')->where('code', 'normal')->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $customerId,
            'current_tier_id' => $normalTierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => 'unverified',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $tierQuote = $service->create(
            'quote.tier.000001',
            $customerId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::Tier, 'normal', 950_000, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('tier-create'),
        );
        self::assertSame(QuoteOverrideSource::Tier, $tierQuote->overrideSource);
        self::assertSame('normal', $tierQuote->overrideReferenceCode);
        self::assertSame(950_000, $tierQuote->finalPriceIrr);
        self::assertNull($tierQuote->agentPricing);

        $this->assertDomainMessage('Tier quote override reference is not current.', fn (): mixed => $service->create(
            'quote.tier.000002',
            $customerId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::Tier, 'vip', 900_000, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('tier-stale'),
        ));

        $agentId = $this->agentSubject('agent-standard');
        $this->assertAuthorizationDenied(fn (): mixed => $service->create(
            'quote.agent.legacy.000001',
            $agentId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::Agent, 'agent-standard', 875_000, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('agent-legacy'),
        ));
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_quote_remains_stable_after_later_offering_price_change_and_new_quote_uses_new_configuration(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $service = $this->app->make(QuoteService::class);
        $pricing = $this->input();
        $first = $service->create('quote.stability.000001', $userId, $offering['id'], $pricing, $this->correlation('stability-first'));

        $offering['service']->update(
            $offering['id'],
            1,
            $this->quoteOfferingDefinition($offering['dependencies'], 1_250_000, true, $offering['code']),
            $this->catalogContext($offering['owner_id'], 'stability-update'),
        );

        $replay = $service->create('quote.stability.000001', $userId, $offering['id'], $pricing, $this->correlation('stability-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame(1_000_000, $replay->basePriceIrr);
        self::assertSame($first->offeringConfigurationHash, $replay->offeringConfigurationHash);

        $second = $service->create('quote.stability.000002', $userId, $offering['id'], $pricing, $this->correlation('stability-second'));
        self::assertFalse($second->replayed);
        self::assertSame(1_250_000, $second->basePriceIrr);
        self::assertSame(2, $second->offeringVersion);
        self::assertNotSame($first->offeringConfigurationHash, $second->offeringConfigurationHash);
        self::assertNull($second->agentPricing);
        self::assertSame(2, DB::table('quotes')->count());
    }

    public function test_expired_quote_is_not_current_but_exact_creation_replay_remains_historical(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering(250_000);
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+5 minutes'));
        $quote = $service->create('quote.expiry.000001', $userId, $offering['id'], $pricing, $this->correlation('expiry-create'));

        $this->clock->value = $this->clock->value->modify('+5 minutes');
        $this->assertRuntimeMessage('Quote has expired.', fn (): mixed => $service->current($quote->quotePublicId));

        $historical = $service->create('quote.expiry.000001', $userId, $offering['id'], $pricing, $this->correlation('expiry-replay'));
        self::assertTrue($historical->replayed);
        self::assertSame($quote->quoteId, $historical->quoteId);
        self::assertSame(250_000, $historical->finalPriceIrr);
        self::assertNull($historical->agentPricing);
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_database_guards_keep_quote_immutable_and_reject_forged_configuration_snapshot(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering(700_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'quote.guard.000001',
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('guard-create'),
        );

        $this->assertQueryRejected(static fn (): int => DB::table('quotes')->where('id', $quote->quoteId)->update(['final_price_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('quotes')->where('id', $quote->quoteId)->delete());

        $stored = DB::table('quotes')->where('id', $quote->quoteId)->first();
        self::assertNotNull($stored);
        /** @var array<string, mixed> $forged */
        $forged = (array) $stored;
        unset($forged['id']);
        $forged['public_id'] = (string) Str::ulid();
        $forged['quote_key'] = 'quote.guard.forged.000001';
        $forged['configuration_snapshot_hash'] = str_repeat('0', 64);
        $this->assertQueryRejected(static fn (): bool => DB::table('quotes')->insert($forged));

        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(700_000, (int) DB::table('quotes')->where('id', $quote->quoteId)->value('final_price_irr'));
        self::assertNull($quote->agentPricing);
    }

    private function input(): QuotePricingInput
    {
        return new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            null,
            0,
            $this->clock->value->modify('+30 minutes'),
        );
    }

    private function correlation(string $suffix): string
    {
        return substr(hash('sha256', 'quote-pricing:'.$suffix), 0, 64);
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (DomainException $exception) {
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

    private function assertInvalidArgumentMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected invalid argument exception.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
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
