<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\ProductVisibility;
use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
use App\Modules\Orders\Application\QuoteDiscountAuthorizationRequest;
use App\Modules\Orders\Application\QuoteDiscountConsumptionRequest;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseDiscountQuote;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class SequencedDiscountPurchaseCatalog implements TelegramCustomerPurchaseCatalog
{
    public int $offeringCalls = 0;

    public function __construct(
        private readonly TelegramCustomerPurchaseOffering $first,
        private readonly TelegramCustomerPurchaseOffering $second,
    ) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramCustomerPurchaseCatalogPage
    {
        throw new RuntimeException('Discount Quote authority test does not request a catalog page.');
    }

    public function offeringForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramCustomerPurchaseOffering
    {
        if ($actorUserId !== $subjectUserId || ! hash_equals($this->first->selectionToken, $selectionToken)) {
            throw new RuntimeException('Unexpected discount Quote test catalog request.');
        }
        $this->offeringCalls++;

        return $this->offeringCalls === 1 ? $this->first : $this->second;
    }
}

/** @requirement BUY-002 PRO-001 PRO-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 QUA-004 */
final class BenefitDiscountQuoteAuthorityTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_discount_grant_authorizes_exact_historical_resolution_and_one_immutable_discounted_quote(): void
    {
        $fixture = $this->discountFixture('authority');
        $authority = $this->app->make(QuoteDiscountAuthority::class);
        $request = new QuoteDiscountAuthorizationRequest(
            'discount-authority-test-0001',
            $fixture['user_id'],
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            'discount-authority-correlation-01',
        );

        $authorization = $authority->authorize($request);
        self::assertSame(90_000, $authorization->discountIrr);
        self::assertSame($fixture['rule']->ruleCode, $authorization->ruleCode);
        self::assertFalse($authorization->replayed);
        self::assertSame(1, DB::table('benefit_code_redemptions')->count());
        self::assertSame(1, DB::table('benefit_code_discount_grants')->count());
        self::assertSame(1, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        $discountedQuote = $this->app->make(QuoteService::class)->create(
            'discount-authority-quote-0001',
            $fixture['user_id'],
            $fixture['offering']['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                $authorization->ruleCode,
                $authorization->discountIrr,
                $this->utcNow()->modify('+15 minutes'),
            ),
            'discount-authority-quote-correlation-01',
        );
        $consumption = $authority->consume(new QuoteDiscountConsumptionRequest(
            'discount-authority-consume-0001',
            $fixture['user_id'],
            $authorization,
            $discountedQuote->quotePublicId,
            $discountedQuote->configurationSnapshotHash,
            'discount-authority-correlation-01',
        ));
        self::assertFalse($consumption->replayed);
        self::assertSame(90_000, $discountedQuote->discountIrr);
        self::assertSame(910_000, $discountedQuote->finalPriceIrr);
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_03_000100_enable_benefit_discount_quote_consumption.php');
        $this->assertExpectedException($migration->down(...), RuntimeException::class);
        self::assertTrue(Schema::hasTable('benefit_code_discount_quote_consumptions'));
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());

        $this->app->make(PromotionRuleService::class)->revise(
            'discount-authority-rule-revision-0001',
            $fixture['rule']->ruleCode,
            new PromotionRuleDefinition(
                PromotionRuleState::Active,
                10,
                PromotionDiscountType::Fixed,
                200_000,
                null,
                0,
                null,
                null,
                null,
                null,
                null,
                false,
                PromotionAudience::Both,
                null,
                null,
                $fixture['offering']['id'],
                null,
                null,
                PromotionAction::Purchase,
                null,
                false,
            ),
            $this->benefitContext($this->benefitOwner(), 'discount-authority-revision'),
        );
        $replay = $authority->authorize($request);
        self::assertTrue($replay->replayed);
        self::assertSame(90_000, $replay->discountIrr);
        self::assertSame($authorization->resolutionPublicId, $replay->resolutionPublicId);
        $consumptionReplay = $authority->consume(new QuoteDiscountConsumptionRequest(
            'discount-authority-consume-0001',
            $fixture['user_id'],
            $replay,
            $discountedQuote->quotePublicId,
            $discountedQuote->configurationSnapshotHash,
            'discount-authority-correlation-01',
        ));
        self::assertTrue($consumptionReplay->replayed);
        self::assertSame($consumption->consumptionPublicId, $consumptionReplay->consumptionPublicId);

        $consumptionId = (int) DB::table('benefit_code_discount_quote_consumptions')->value('id');
        $this->assertExpectedException(fn () => DB::table('benefit_code_discount_quote_consumptions')->where('id', $consumptionId)->update(['discount_irr' => 1]), QueryException::class);
        $this->assertExpectedException(fn () => DB::table('benefit_code_discount_quote_consumptions')->where('id', $consumptionId)->delete(), QueryException::class);

        $serialized = json_encode([
            DB::table('benefit_code_redemptions')->value('configuration_snapshot'),
            DB::table('benefit_code_discount_grants')->value('configuration_snapshot'),
            DB::table('pricing_rule_resolutions')->value('configuration_snapshot'),
            DB::table('benefit_code_discount_quote_consumptions')->value('configuration_snapshot'),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(str_replace('-', '', strtoupper($fixture['code'])), strtoupper($serialized));
    }

    public function test_same_requote_operation_key_with_changed_code_conflicts_without_second_effect(): void
    {
        $fixture = $this->discountFixture('changed-code-replay');
        $offeringCode = (string) DB::table('plan_offerings')->where('id', $fixture['offering']['id'])->value('code');
        $offering = $this->telegramOffering($offeringCode, 1_000_000);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, new SequencedDiscountPurchaseCatalog($offering, $offering));
        $service = $this->app->make(TelegramCustomerPurchaseDiscountQuote::class);
        $acceptedAt = $this->utcNow();
        $operationKey = hash('sha256', 'changed-code-replay-operation');

        $service->requoteForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $offering->selectionToken,
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            $acceptedAt,
            $operationKey,
        );
        $before = [
            'redemptions' => DB::table('benefit_code_redemptions')->count(),
            'grants' => DB::table('benefit_code_discount_grants')->count(),
            'resolutions' => DB::table('pricing_rule_resolutions')->count(),
            'consumptions' => DB::table('benefit_code_discount_quote_consumptions')->count(),
            'quotes' => DB::table('quotes')->count(),
        ];

        $this->assertExpectedException(fn () => $service->requoteForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $offering->selectionToken,
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'].'X',
            $acceptedAt,
            $operationKey,
        ), RuntimeException::class);

        self::assertSame($before, [
            'redemptions' => DB::table('benefit_code_redemptions')->count(),
            'grants' => DB::table('benefit_code_discount_grants')->count(),
            'resolutions' => DB::table('pricing_rule_resolutions')->count(),
            'consumptions' => DB::table('benefit_code_discount_quote_consumptions')->count(),
            'quotes' => DB::table('quotes')->count(),
        ]);
    }

    public function test_expired_source_quote_fails_before_redemption_resolution_or_discounted_quote(): void
    {
        $fixture = $this->discountFixture('expired-source');
        $future = $this->utcNow()->modify('+2 hours');
        $this->app->instance(Clock::class, new readonly class($future) implements Clock
        {
            public function __construct(private DateTimeImmutable $value) {}

            public function now(): DateTimeImmutable
            {
                return $this->value;
            }
        });
        $authority = $this->app->make(QuoteDiscountAuthority::class);

        $this->assertExpectedException(fn () => $authority->authorize(new QuoteDiscountAuthorizationRequest(
            'discount-expired-source-auth-0001',
            $fixture['user_id'],
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            'discount-expired-source-correlation',
        )), DomainException::class);

        self::assertSame(0, DB::table('benefit_code_redemptions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_grants')->count());
        self::assertSame(0, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_discount_quote_consumption_migration_reentry_converges_and_empty_rollback_is_recoverable(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_03_000100_enable_benefit_discount_quote_consumption.php');
        $migration->up();
        self::assertTrue(Schema::hasColumn('benefit_code_discount_quote_consumptions', 'discounted_quote_configuration_hash'));
        self::assertSame(3, DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TRIGGER_NAME', [
                'benefit_discount_quote_consumptions_insert_guard',
                'benefit_discount_quote_consumptions_update_guard',
                'benefit_discount_quote_consumptions_delete_guard',
            ])->count());

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP INDEX benefit_code_discount_quote_consumptions_consumption_key_unique');
        $migration->up();
        self::assertSame(1, DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('INDEX_NAME', 'benefit_code_discount_quote_consumptions_consumption_key_unique')
            ->where('NON_UNIQUE', 0)
            ->count());

        $migration->down();
        self::assertFalse(Schema::hasTable('benefit_code_discount_quote_consumptions'));
        Schema::create('benefit_code_discount_quote_consumptions', static function (Blueprint $table): void {
            $table->bigIncrements('id');
        });
        self::assertTrue(Schema::hasTable('benefit_code_discount_quote_consumptions'));
        self::assertFalse(Schema::hasColumn('benefit_code_discount_quote_consumptions', 'public_id'));

        $migration->up();
        self::assertTrue(Schema::hasColumn('benefit_code_discount_quote_consumptions', 'public_id'));
        self::assertTrue(Schema::hasColumn('benefit_code_discount_quote_consumptions', 'discounted_quote_configuration_hash'));
        self::assertSame(3, DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TRIGGER_NAME', [
                'benefit_discount_quote_consumptions_insert_guard',
                'benefit_discount_quote_consumptions_update_guard',
                'benefit_discount_quote_consumptions_delete_guard',
            ])->count());
        $migration->up();
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_same_grant_cannot_authorize_two_committed_discounted_quotes(): void
    {
        $fixture = $this->discountFixture('single-consumption');
        $authority = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $authority->authorize(new QuoteDiscountAuthorizationRequest(
            'discount-single-auth-0001',
            $fixture['user_id'],
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            'discount-single-correlation-01',
        ));
        $first = $this->discountedQuote($fixture, $authorization->ruleCode, $authorization->discountIrr, 'first');
        $authority->consume(new QuoteDiscountConsumptionRequest(
            'discount-single-consume-0001',
            $fixture['user_id'],
            $authorization,
            $first->quotePublicId,
            $first->configurationSnapshotHash,
            'discount-single-correlation-01',
        ));

        $beforeQuotes = DB::table('quotes')->count();
        $this->assertExpectedException(function () use ($fixture, $authorization, $authority): void {
            DB::transaction(function () use ($fixture, $authorization, $authority): void {
                $second = $this->discountedQuote($fixture, $authorization->ruleCode, $authorization->discountIrr, 'second');
                $authority->consume(new QuoteDiscountConsumptionRequest(
                    'discount-single-consume-0002',
                    $fixture['user_id'],
                    $authorization,
                    $second->quotePublicId,
                    $second->configurationSnapshotHash,
                    'discount-single-correlation-02',
                ));
            });
        }, QueryException::class);
        self::assertSame($beforeQuotes, DB::table('quotes')->count());
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_full_discount_requote_path_exact_replay_returns_same_quote_and_consumption_with_accepted_time_expiry(): void
    {
        $fixture = $this->discountFixture('full-replay');
        $offeringCode = (string) DB::table('plan_offerings')->where('id', $fixture['offering']['id'])->value('code');
        $offering = $this->telegramOffering($offeringCode, 1_000_000);
        $catalog = new SequencedDiscountPurchaseCatalog($offering, $offering);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $service = $this->app->make(TelegramCustomerPurchaseDiscountQuote::class);
        $acceptedAt = $this->utcNow();
        $operationKey = hash('sha256', 'full-discount-replay-operation');

        $first = $service->requoteForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $offering->selectionToken,
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            $acceptedAt,
            $operationKey,
        );
        self::assertFalse($first->replayed);
        self::assertSame(90_000, $first->quote->discountIrr);
        self::assertSame(910_000, $first->quote->finalPriceIrr);
        self::assertSame(900, $first->quote->expiresAt->getTimestamp() - $acceptedAt->getTimestamp());
        self::assertSame(1, DB::table('benefit_code_redemptions')->count());
        self::assertSame(1, DB::table('benefit_code_discount_grants')->count());
        self::assertSame(1, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
        self::assertSame(2, DB::table('quotes')->count());

        $replay = $service->requoteForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $offering->selectionToken,
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            $acceptedAt,
            $operationKey,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->quote->quotePublicId, $replay->quote->quotePublicId);
        self::assertSame($first->quote->configurationSnapshotHash, $replay->quote->configurationSnapshotHash);
        self::assertSame($first->discountConsumptionPublicId, $replay->discountConsumptionPublicId);
        self::assertSame($first->promotionResolutionPublicId, $replay->promotionResolutionPublicId);
        self::assertSame(1, DB::table('benefit_code_redemptions')->count());
        self::assertSame(1, DB::table('benefit_code_discount_grants')->count());
        self::assertSame(1, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
        self::assertSame(2, DB::table('quotes')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
    }

    public function test_outer_requote_transaction_rolls_back_redemption_resolution_quote_and_consumption_on_post_lock_catalog_drift(): void
    {
        $fixture = $this->discountFixture('atomic-rollback');
        $offeringCode = (string) DB::table('plan_offerings')->where('id', $fixture['offering']['id'])->value('code');
        $first = $this->telegramOffering($offeringCode, 1_000_000);
        $second = $this->telegramOffering($offeringCode, 1_000_001);
        $catalog = new SequencedDiscountPurchaseCatalog($first, $second);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $service = $this->app->make(TelegramCustomerPurchaseDiscountQuote::class);

        $this->assertExpectedException(fn () => $service->requoteForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $first->selectionToken,
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            $this->utcNow(),
            hash('sha256', 'atomic-rollback-operation'),
        ), RuntimeException::class);

        self::assertSame(2, $catalog->offeringCalls);
        self::assertSame(0, DB::table('benefit_code_redemptions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_grants')->count());
        self::assertSame(0, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
        self::assertSame(1, DB::table('quotes')->count());
    }

    /** @return array{user_id:int,offering:array{id:int,product_id:int,server_id:int},rule:object,code:string,source_quote:QuoteReceipt} */
    private function discountFixture(string $suffix): array
    {
        $offering = $this->activeBenefitOffering('discount-'.$suffix);
        $currentVersion = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            $currentVersion,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'discount-offering-visible-'.substr(hash('sha256', $suffix), 0, 24),
                'discount-offering-correlation-'.substr(hash('sha256', $suffix), 0, 20),
                'benefit_discount_quote_test',
                'Expose the verified test Offering to the customer purchase catalog.',
                $this->benefitOwner(),
            ),
        );
        $userId = $this->benefitUser('customer');
        $rule = $this->usageRule($offering['id'], 'discount.rule.'.substr(hash('sha256', $suffix), 0, 16), 90_000);
        $this->benefitCampaign(
            'benefit.discount.'.substr(hash('sha256', $suffix), 0, 12),
            BenefitCodeType::DiscountGrant,
            $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']),
            'discount-'.$suffix,
        );
        $issued = $this->benefitIssue('benefit.discount.'.substr(hash('sha256', $suffix), 0, 12), 'discount-'.$suffix);
        $code = (string) $issued->items[0]->fullCode;
        $sourceQuote = $this->app->make(QuoteService::class)->create(
            'discount-source-quote-'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->utcNow()->modify('+30 minutes')),
            'discount-source-correlation-'.substr(hash('sha256', $suffix), 0, 20),
        );

        return compact('userId', 'offering', 'rule', 'code', 'sourceQuote') + [
            'user_id' => $userId,
            'source_quote' => $sourceQuote,
        ];
    }

    private function discountedQuote(array $fixture, string $ruleCode, int $discountIrr, string $suffix): QuoteReceipt
    {
        return $this->app->make(QuoteService::class)->create(
            'discount-extra-quote-'.substr(hash('sha256', $suffix), 0, 24),
            $fixture['user_id'],
            $fixture['offering']['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, $ruleCode, $discountIrr, $this->utcNow()->modify('+15 minutes')),
            'discount-extra-correlation-'.substr(hash('sha256', $suffix), 0, 20),
        );
    }

    private function telegramOffering(string $code, int $price): TelegramCustomerPurchaseOffering
    {
        return new TelegramCustomerPurchaseOffering(
            str_repeat('c', 40),
            $code,
            'دسته خرید',
            'Purchase category',
            'سرویس تخفیف',
            'Discount service',
            null,
            null,
            'استاندارد',
            'Standard',
            $price,
            30,
            null,
            1,
        );
    }

    private function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @param class-string<\Throwable> $class */
    private function assertExpectedException(callable $callback, string $class): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($class, $exception);

            return;
        }
        self::fail('Expected '.$class.' was not thrown.');
    }
}
