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
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuoteRefreshRequired;
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
use Illuminate\Support\Str;
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

    public function test_provenance_insert_guard_rejects_extra_plaintext_and_wrong_public_identity_snapshots(): void
    {
        $fixture = $this->discountFixture('snapshot-guard');
        $authority = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $authority->authorize(new QuoteDiscountAuthorizationRequest(
            'discount-snapshot-guard-auth-0001',
            $fixture['user_id'],
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            'discount-snapshot-guard-correlation',
        ));
        $discountedQuote = $this->discountedQuote(
            $fixture,
            $authorization->ruleCode,
            $authorization->discountIrr,
            'snapshot-guard',
        );
        $grantId = (int) DB::table('benefit_code_discount_grants')->where('public_id', $authorization->grantPublicId)->value('id');
        $resolutionId = (int) DB::table('pricing_rule_resolutions')->where('public_id', $authorization->resolutionPublicId)->value('id');
        $sourceQuoteId = (int) DB::table('quotes')->where('public_id', $authorization->sourceQuotePublicId)->value('id');
        $discountedQuoteId = (int) DB::table('quotes')->where('public_id', $discountedQuote->quotePublicId)->value('id');
        $baseSnapshot = [
            'discount_irr' => $authorization->discountIrr,
            'discounted_quote_configuration_hash' => $discountedQuote->configurationSnapshotHash,
            'discounted_quote_public_id' => $discountedQuote->quotePublicId,
            'grant_configuration_hash' => $authorization->grantConfigurationHash,
            'grant_public_id' => $authorization->grantPublicId,
            'resolution_configuration_hash' => $authorization->resolutionConfigurationHash,
            'resolution_public_id' => $authorization->resolutionPublicId,
            'rule_code' => $authorization->ruleCode,
            'source_quote_configuration_hash' => $authorization->sourceQuoteConfigurationHash,
            'source_quote_public_id' => $authorization->sourceQuotePublicId,
        ];
        ksort($baseSnapshot, SORT_STRING);

        $insert = function (array $snapshot, string $key) use (
            $fixture,
            $authorization,
            $discountedQuote,
            $grantId,
            $resolutionId,
            $sourceQuoteId,
            $discountedQuoteId,
        ): void {
            ksort($snapshot, SORT_STRING);
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            DB::table('benefit_code_discount_quote_consumptions')->insert([
                'public_id' => (string) Str::ulid(),
                'consumption_key' => $key,
                'request_payload_hash' => str_repeat('f', 64),
                'user_id' => $fixture['user_id'],
                'benefit_code_discount_grant_id' => $grantId,
                'pricing_rule_resolution_id' => $resolutionId,
                'source_quote_id' => $sourceQuoteId,
                'discounted_quote_id' => $discountedQuoteId,
                'rule_code_snapshot' => $authorization->ruleCode,
                'discount_irr' => $authorization->discountIrr,
                'grant_configuration_hash' => $authorization->grantConfigurationHash,
                'pricing_rule_resolution_configuration_hash' => $authorization->resolutionConfigurationHash,
                'source_quote_configuration_hash' => $authorization->sourceQuoteConfigurationHash,
                'discounted_quote_configuration_hash' => $discountedQuote->configurationSnapshotHash,
                'configuration_snapshot' => $json,
                'configuration_hash' => hash('sha256', $json),
                'correlation_id' => 'snapshot-guard-correlation',
                'created_at' => $this->utcNow()->format('Y-m-d H:i:s.u'),
            ]);
        };

        $plaintextSnapshot = $baseSnapshot + ['submitted_code' => $fixture['code']];
        $this->assertExpectedException(
            fn () => $insert($plaintextSnapshot, 'discount-snapshot-extra-plaintext-0001'),
            QueryException::class,
        );
        $wrongIdentitySnapshot = $baseSnapshot;
        $wrongIdentitySnapshot['source_quote_public_id'] = str_pad('01Z', 26, '0');
        $this->assertExpectedException(
            fn () => $insert($wrongIdentitySnapshot, 'discount-snapshot-wrong-identity-0001'),
            QueryException::class,
        );
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
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

    public function test_expired_source_quote_maps_to_telegram_refresh_before_discount_authority_effect(): void
    {
        $fixture = $this->discountFixture('expired-source-telegram');
        $future = $this->utcNow()->modify('+2 hours');
        $this->app->instance(Clock::class, new readonly class($future) implements Clock
        {
            public function __construct(private DateTimeImmutable $value) {}

            public function now(): DateTimeImmutable
            {
                return $this->value;
            }
        });
        $offeringCode = (string) DB::table('plan_offerings')->where('id', $fixture['offering']['id'])->value('code');
        $offering = $this->telegramOffering($offeringCode, 1_000_000);
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, new SequencedDiscountPurchaseCatalog($offering, $offering));
        $service = $this->app->make(TelegramCustomerPurchaseDiscountQuote::class);

        $this->assertExpectedException(fn () => $service->requoteForSelf(
            $fixture['user_id'],
            $fixture['user_id'],
            $offering->selectionToken,
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            $this->utcNow(),
            hash('sha256', 'expired-source-telegram-operation'),
        ), TelegramCustomerPurchaseQuoteRefreshRequired::class);

        self::assertSame(0, DB::table('benefit_code_redemptions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_grants')->count());
        self::assertSame(0, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
        self::assertSame(1, DB::table('quotes')->count());
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

        DB::unprepared('DROP TRIGGER benefit_discount_quote_consumptions_insert_guard');
        DB::unprepared('CREATE TRIGGER benefit_discount_quote_consumptions_insert_guard BEFORE INSERT ON benefit_code_discount_quote_consumptions FOR EACH ROW BEGIN SET @benefit_discount_quote_noop = 1; END');
        $migration->up();
        $insertGuard = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', 'benefit_discount_quote_consumptions_insert_guard')
            ->value('ACTION_STATEMENT');
        self::assertIsString($insertGuard);
        self::assertStringContainsString(
            "JSON_EXTRACT(NEW.configuration_snapshot, '$.grant_public_id')",
            $insertGuard,
        );
        self::assertStringContainsString('Benefit discount Quote consumption identity mismatch.', $insertGuard);

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

    public function test_migration_reentry_rejects_non_empty_constraint_drift_without_destructive_rebuild(): void
    {
        $fixture = $this->discountFixture('migration-non-empty-readiness');
        $authority = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $authority->authorize(new QuoteDiscountAuthorizationRequest(
            'discount-migration-non-empty-auth-0001',
            $fixture['user_id'],
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            'discount-migration-non-empty-correlation',
        ));
        $discountedQuote = $this->discountedQuote(
            $fixture,
            $authorization->ruleCode,
            $authorization->discountIrr,
            'migration-non-empty-readiness',
        );
        $authority->consume(new QuoteDiscountConsumptionRequest(
            'discount-migration-non-empty-consume-0001',
            $fixture['user_id'],
            $authorization,
            $discountedQuote->quotePublicId,
            $discountedQuote->configurationSnapshotHash,
            'discount-migration-non-empty-correlation',
        ));
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_03_000100_enable_benefit_discount_quote_consumption.php');
        $database = DB::connection()->getDatabaseName();
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP FOREIGN KEY bdqc_user_fk');
        DB::statement(
            'ALTER TABLE benefit_code_discount_quote_consumptions '.
            'ADD CONSTRAINT bdqc_user_fk FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) '.
            'ON DELETE CASCADE ON UPDATE CASCADE',
        );
        DB::statement(
            'ALTER TABLE benefit_code_discount_quote_consumptions '.
            'ADD CONSTRAINT bdqc_nonempty_unexpected_check CHECK (`discount_irr` >= 1 AND CHAR_LENGTH(`rule_code_snapshot`) > 0)',
        );

        try {
            $migration->up();
            self::fail('Expected non-empty constraint drift to fail safe without destructive rebuild.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Benefit discount Quote authority cannot repair a non-empty incomplete surface.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
        $rules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', 'bdqc_user_fk')
            ->first(['DELETE_RULE', 'UPDATE_RULE']);
        self::assertNotNull($rules);
        self::assertSame('CASCADE', (string) $rules->DELETE_RULE);
        self::assertSame('CASCADE', (string) $rules->UPDATE_RULE);
        self::assertTrue(DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', 'bdqc_nonempty_unexpected_check')
            ->exists());

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP CONSTRAINT bdqc_nonempty_unexpected_check');
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP FOREIGN KEY bdqc_user_fk');
        DB::statement(
            'ALTER TABLE benefit_code_discount_quote_consumptions '.
            'ADD CONSTRAINT bdqc_user_fk FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) '.
            'ON DELETE RESTRICT ON UPDATE RESTRICT',
        );
        $migration->up();
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_migration_reentry_rejects_non_empty_restrictive_unique_index_without_destructive_rebuild(): void
    {
        $fixture = $this->discountFixture('migration-non-empty-unique-readiness');
        $authority = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $authority->authorize(new QuoteDiscountAuthorizationRequest(
            'discount-migration-unique-auth-0001',
            $fixture['user_id'],
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            'discount-migration-unique-correlation',
        ));
        $discountedQuote = $this->discountedQuote(
            $fixture,
            $authorization->ruleCode,
            $authorization->discountIrr,
            'migration-non-empty-unique-readiness',
        );
        $authority->consume(new QuoteDiscountConsumptionRequest(
            'discount-migration-unique-consume-0001',
            $fixture['user_id'],
            $authorization,
            $discountedQuote->quotePublicId,
            $discountedQuote->configurationSnapshotHash,
            'discount-migration-unique-correlation',
        ));
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_03_000100_enable_benefit_discount_quote_consumption.php');
        $database = DB::connection()->getDatabaseName();
        $indexName = 'bdqc_nonempty_unexpected_user_uq';
        DB::statement(
            'CREATE UNIQUE INDEX '.$indexName.' '.
            'ON benefit_code_discount_quote_consumptions (`user_id`)',
        );

        try {
            $migration->up();
            self::fail('Expected non-empty restrictive unique-index drift to fail safe without destructive rebuild.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Benefit discount Quote authority cannot repair a non-empty incomplete surface.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
        self::assertSame(1, DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('INDEX_NAME', $indexName)
            ->where('NON_UNIQUE', 0)
            ->count());

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP INDEX '.$indexName);
        $migration->up();
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_same_user_can_commit_independent_discount_consumptions(): void
    {
        $firstFixture = $this->discountFixture('same-user-first');
        $secondFixture = $this->discountFixture('same-user-second', $firstFixture['user_id']);
        $authority = $this->app->make(QuoteDiscountAuthority::class);

        foreach ([['first', $firstFixture], ['second', $secondFixture]] as [$suffix, $fixture]) {
            $authorization = $authority->authorize(new QuoteDiscountAuthorizationRequest(
                'discount-same-user-'.$suffix.'-auth-0001',
                $fixture['user_id'],
                $fixture['source_quote']->quotePublicId,
                $fixture['source_quote']->configurationSnapshotHash,
                $fixture['code'],
                'discount-same-user-'.$suffix.'-correlation',
            ));
            $discountedQuote = $this->discountedQuote(
                $fixture,
                $authorization->ruleCode,
                $authorization->discountIrr,
                'same-user-'.$suffix,
            );
            $authority->consume(new QuoteDiscountConsumptionRequest(
                'discount-same-user-'.$suffix.'-consume-0001',
                $fixture['user_id'],
                $authorization,
                $discountedQuote->quotePublicId,
                $discountedQuote->configurationSnapshotHash,
                'discount-same-user-'.$suffix.'-correlation',
            ));
        }

        self::assertSame($firstFixture['user_id'], $secondFixture['user_id']);
        self::assertSame(2, DB::table('benefit_code_discount_quote_consumptions')
            ->where('user_id', $firstFixture['user_id'])
            ->count());
    }

    public function test_migration_reentry_rejects_non_empty_backtick_literal_drift_without_destructive_rebuild(): void
    {
        $fixture = $this->discountFixture('migration-non-empty-literal-readiness');
        $authority = $this->app->make(QuoteDiscountAuthority::class);
        $authorization = $authority->authorize(new QuoteDiscountAuthorizationRequest(
            'discount-migration-literal-auth-0001',
            $fixture['user_id'],
            $fixture['source_quote']->quotePublicId,
            $fixture['source_quote']->configurationSnapshotHash,
            $fixture['code'],
            'discount-migration-literal-correlation',
        ));
        $discountedQuote = $this->discountedQuote(
            $fixture,
            $authorization->ruleCode,
            $authorization->discountIrr,
            'migration-non-empty-literal-readiness',
        );
        $authority->consume(new QuoteDiscountConsumptionRequest(
            'discount-migration-literal-consume-0001',
            $fixture['user_id'],
            $authorization,
            $discountedQuote->quotePublicId,
            $discountedQuote->configurationSnapshotHash,
            'discount-migration-literal-correlation',
        ));
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_03_000100_enable_benefit_discount_quote_consumption.php');
        $database = DB::connection()->getDatabaseName();
        $constraintName = 'benefit_discount_quote_consumption_hashes_chk';
        $canonicalClause = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', $constraintName)
            ->value('CHECK_CLAUSE');
        self::assertIsString($canonicalClause);
        $driftedClause = str_replace("'^[0-9a-f]{64}$'", "'^[0-9a-f`]{64}$'", $canonicalClause, $replacementCount);
        self::assertGreaterThan(0, $replacementCount);
        self::assertNotSame($canonicalClause, $driftedClause);
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP CONSTRAINT '.$constraintName);
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT '.$constraintName.' CHECK ('.$driftedClause.')');

        try {
            $migration->up();
            self::fail('Expected non-empty literal drift to fail safe without destructive rebuild.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Benefit discount Quote authority cannot repair a non-empty incomplete surface.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
        $installedDrift = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', $constraintName)
            ->value('CHECK_CLAUSE');
        self::assertIsString($installedDrift);
        self::assertStringContainsString("'^[0-9a-f`]{64}$'", $installedDrift);

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP CONSTRAINT '.$constraintName);
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT '.$constraintName.' CHECK ('.$canonicalClause.')');
        $migration->up();
        self::assertSame(1, DB::table('benefit_code_discount_quote_consumptions')->count());
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
        ), TelegramCustomerPurchaseQuoteRefreshRequired::class);

        self::assertSame(2, $catalog->offeringCalls);
        self::assertSame(0, DB::table('benefit_code_redemptions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_grants')->count());
        self::assertSame(0, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
        self::assertSame(1, DB::table('quotes')->count());
    }

    /** @return array{user_id:int,offering:array{id:int,product_id:int,server_id:int},rule:object,code:string,source_quote:QuoteReceipt} */
    private function discountFixture(string $suffix, ?int $existingUserId = null): array
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
        $userId = $existingUserId ?? $this->benefitUser('customer');
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
