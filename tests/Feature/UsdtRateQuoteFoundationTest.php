<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\OfferingOperationCode;
use App\Modules\Catalog\Domain\OfferingOperationPolicy;
use App\Modules\Catalog\Domain\OfferingPackageDefinition;
use App\Modules\Catalog\Domain\OfferingPackageType;
use App\Modules\Catalog\Domain\OfferingProtocolAssignment;
use App\Modules\Catalog\Domain\PlanOfferingAudience;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\PlanOfferingProtocolSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServiceMode;
use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Usdt\Application\UsdtAmountQuoteService;
use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtDestinationWalletService;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\UsdtAccessFoundationSeeder;
use DateTimeImmutable;
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

final class MutableUsdtPersistenceClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class PersistenceUsdtRateProvider implements UsdtRateProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly string $providerCode,
        public string $rateIrr,
        public DateTimeImmutable $fetchedAt,
    ) {}

    public function code(): string
    {
        return $this->providerCode;
    }

    public function fetch(UsdtRateSide $side): UsdtRate
    {
        $this->calls++;

        return new UsdtRate(
            $this->providerCode,
            $this->rateIrr,
            $this->fetchedAt,
            hash('sha256', $this->providerCode.'|'.$this->rateIrr.'|'.$this->fetchedAt->format(DATE_ATOM).'|'.$side->value),
        );
    }
}

/** @requirement USDT-001 USDT-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
final class UsdtRateQuoteFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(UsdtAccessFoundationSeeder::class);
    }

    public function test_destination_management_is_authorized_versioned_replay_safe_and_database_immutable(): void
    {
        $clock = $this->clock();
        $service = $this->destinationService($clock);
        $unauthorized = $this->administrator(false);
        $owner = $this->administrator(true);
        $addressOne = '0x'.str_repeat('11', 20);
        $addressTwo = '0x'.str_repeat('22', 20);

        $this->assertAuthorizationDenied(fn () => $service->configure(
            'usdt.wallet.denied.0001',
            $unauthorized,
            'primary',
            $addressOne,
            true,
            'Denied test.',
            $this->correlation('wallet-denied'),
        ));
        self::assertSame(0, DB::table('usdt_destination_wallet_versions')->count());

        $first = $service->configure(
            'usdt.wallet.primary.0001',
            $owner,
            'primary',
            $addressOne,
            true,
            'Initial BEP20 destination.',
            $this->correlation('wallet-v1'),
        );
        self::assertSame(1, $first->version);
        self::assertSame('BEP20', $first->network);
        self::assertSame($addressOne, $first->address);
        self::assertFalse($first->replayed);

        $replay = $service->configure(
            'usdt.wallet.primary.0001',
            $owner,
            'primary',
            $addressOne,
            true,
            'Initial BEP20 destination.',
            $this->correlation('wallet-v1-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->versionId, $replay->versionId);
        self::assertSame(1, DB::table('usdt_destination_wallet_versions')->count());

        $this->assertRuntimeMessage('USDT destination mutation key conflict.', fn () => $service->configure(
            'usdt.wallet.primary.0001',
            $owner,
            'primary',
            $addressTwo,
            true,
            'Initial BEP20 destination.',
            $this->correlation('wallet-conflict'),
        ));

        $second = $service->configure(
            'usdt.wallet.primary.0002',
            $owner,
            'primary',
            $addressTwo,
            true,
            'Rotate public destination.',
            $this->correlation('wallet-v2'),
        );
        self::assertSame(2, $second->version);
        self::assertSame($addressTwo, $service->current('primary')->address);

        $this->assertQueryRejected(static fn (): int => DB::table('usdt_destination_wallet_versions')
            ->where('id', $first->versionId)
            ->update(['address' => '0x'.str_repeat('33', 20)]));
        $this->assertQueryRejected(static fn (): int => DB::table('usdt_destination_wallet_versions')
            ->where('id', $first->versionId)
            ->delete());
    }

    public function test_amount_quote_snapshots_rate_and_destination_replays_exactly_and_remains_historical(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $source = $this->sourceQuote($clock, $userId, 1_000_001, '+30 minutes', 'usdt-source-main');
        $owner = $this->administrator(true);
        $destinations = $this->destinationService($clock);
        $addressOne = '0x'.str_repeat('44', 20);
        $addressTwo = '0x'.str_repeat('55', 20);
        $walletOne = $destinations->configure(
            'usdt.wallet.quote.0001',
            $owner,
            'primary',
            $addressOne,
            true,
            'USDT quote destination v1.',
            $this->correlation('quote-wallet-v1'),
        );
        $primary = new PersistenceUsdtRateProvider('nobitex', '1000000', $clock->value);
        $secondary = new PersistenceUsdtRateProvider('secondary', '1005000', $clock->value);
        $service = $this->amountService($clock, $destinations, $primary, $secondary, 100, 6, 900, 120);
        $paymentIntentCount = DB::table('payment_intents')->count();
        $ledgerCount = DB::table('ledger_transactions')->count();

        $created = $service->create('usdt.amount.quote.0001', $source->quotePublicId);
        self::assertFalse($created->replayed);
        self::assertSame('BEP20', $created->network);
        self::assertSame($addressOne, $created->destinationAddress);
        self::assertSame($walletOne->version, $created->destinationWalletVersion);
        self::assertSame('nobitex', $created->rateSource);
        self::assertSame('1000000.00000000', $created->rawRateIrr);
        self::assertSame(100, $created->marginBps);
        self::assertSame('990000.00000000', $created->finalRateIrr);
        self::assertSame(1_000_001, $created->orderAmountIrr);
        self::assertSame('1.010103', $created->exactUsdt);
        self::assertSame(6, $created->roundingPrecision);
        self::assertSame($clock->value->modify('+120 seconds')->format(DATE_ATOM), $created->expiresAt->format(DATE_ATOM));
        self::assertSame($paymentIntentCount, DB::table('payment_intents')->count());
        self::assertSame($ledgerCount, DB::table('ledger_transactions')->count());

        /** @var string $snapshotJson */
        $snapshotJson = DB::table('usdt_amount_quotes')->where('id', $created->quoteId)->value('configuration_snapshot');
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('usdt-quote-v1', $snapshot['formula_version']);
        self::assertSame($source->quotePublicId, $snapshot['source_quote_public_id']);
        self::assertSame($walletOne->configurationSnapshotHash, $snapshot['destination_configuration_hash']);
        self::assertSame('1000000.00000000', $snapshot['raw_rate_irr']);
        self::assertSame('990000.00000000', $snapshot['final_rate_irr']);
        self::assertSame('1.010103', $snapshot['exact_usdt']);
        self::assertSame(120, $snapshot['rate_max_age_seconds']);
        self::assertSame(900, $snapshot['quote_validity_seconds']);
        self::assertSame($created->configurationSnapshotHash, hash('sha256', $snapshotJson));

        $replay = $service->create('usdt.amount.quote.0001', $source->quotePublicId);
        self::assertTrue($replay->replayed);
        self::assertSame($created->quoteId, $replay->quoteId);
        self::assertSame($created->exactUsdt, $replay->exactUsdt);

        $walletTwo = $destinations->configure(
            'usdt.wallet.quote.0002',
            $owner,
            'primary',
            $addressTwo,
            true,
            'USDT quote destination v2.',
            $this->correlation('quote-wallet-v2'),
        );
        $primary->rateIrr = '2000000';
        $secondary->rateIrr = '2010000';

        $historical = $service->create('usdt.amount.quote.0001', $source->quotePublicId);
        self::assertTrue($historical->replayed);
        self::assertSame($addressOne, $historical->destinationAddress);
        self::assertSame('1000000.00000000', $historical->rawRateIrr);
        self::assertSame('1.010103', $historical->exactUsdt);

        $newQuote = $service->create('usdt.amount.quote.0002', $source->quotePublicId);
        self::assertSame($walletTwo->version, $newQuote->destinationWalletVersion);
        self::assertSame($addressTwo, $newQuote->destinationAddress);
        self::assertSame('2000000.00000000', $newQuote->rawRateIrr);
        self::assertNotSame($created->exactUsdt, $newQuote->exactUsdt);

        $otherSource = $this->sourceQuote($clock, $userId, 1_100_001, '+30 minutes', 'usdt-source-conflict');
        $this->assertRuntimeMessage('USDT amount quote key conflict.', fn () => $service->create(
            'usdt.amount.quote.0001',
            $otherSource->quotePublicId,
        ));

        $clock->value = $clock->value->modify('+31 minutes');
        $historicalAfterExpiry = $service->create('usdt.amount.quote.0001', $source->quotePublicId);
        self::assertTrue($historicalAfterExpiry->replayed);
        self::assertSame($created->quoteId, $historicalAfterExpiry->quoteId);
        $primary->fetchedAt = $clock->value;
        $secondary->fetchedAt = $clock->value;
        $this->assertRuntimeMessage('Quote has expired.', fn () => $service->create(
            'usdt.amount.quote.expired.0001',
            $source->quotePublicId,
        ));
    }

    public function test_database_guards_reject_forged_or_mutated_usdt_amount_quotes(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $source = $this->sourceQuote($clock, $userId, 750_001, '+20 minutes', 'usdt-source-guard');
        $owner = $this->administrator(true);
        $destinations = $this->destinationService($clock);
        $destinations->configure(
            'usdt.wallet.guard.0001',
            $owner,
            'primary',
            '0x'.str_repeat('66', 20),
            true,
            'Guard destination.',
            $this->correlation('guard-wallet'),
        );
        $primary = new PersistenceUsdtRateProvider('nobitex', '1000000', $clock->value);
        $secondary = new PersistenceUsdtRateProvider('secondary', '1001000', $clock->value);
        $created = $this->amountService($clock, $destinations, $primary, $secondary, 0, 6, 900, 120)
            ->create('usdt.amount.guard.0001', $source->quotePublicId);

        $this->assertQueryRejected(static fn (): int => DB::table('usdt_amount_quotes')
            ->where('id', $created->quoteId)
            ->update(['exact_usdt' => '999.000000']));
        $this->assertQueryRejected(static fn (): int => DB::table('usdt_amount_quotes')
            ->where('id', $created->quoteId)
            ->delete());

        $stored = DB::table('usdt_amount_quotes')->where('id', $created->quoteId)->first();
        self::assertNotNull($stored);
        /** @var array<string, mixed> $forged */
        $forged = (array) $stored;
        unset($forged['id']);
        $forged['public_id'] = (string) Str::ulid();
        $forged['quote_key'] = 'usdt.amount.guard.forged.0001';
        $forged['exact_usdt'] = '999.000000';
        $this->assertQueryRejected(static fn (): bool => DB::table('usdt_amount_quotes')->insert($forged));

        self::assertSame(1, DB::table('usdt_amount_quotes')->count());
    }

    private function amountService(
        MutableUsdtPersistenceClock $clock,
        UsdtDestinationWalletService $destinations,
        PersistenceUsdtRateProvider $primary,
        PersistenceUsdtRateProvider $secondary,
        int $marginBps,
        int $precision,
        int $validitySeconds,
        int $rateMaxAgeSeconds,
    ): UsdtAmountQuoteService {
        $policy = new UsdtRatePolicy(
            ['nobitex', 'secondary'],
            UsdtRateSide::Buy,
            $rateMaxAgeSeconds,
            '100000',
            '10000000',
            500,
            false,
            3,
            60,
        );
        $resolver = new UsdtRateResolver(
            [$primary, $secondary],
            $policy,
            new UsdtCircuitBreaker(new Repository(new ArrayStore), $clock, 3, 60),
            $clock,
        );

        return new UsdtAmountQuoteService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(QuoteService::class),
            $destinations,
            $resolver,
            $clock,
            $marginBps,
            $precision,
            $validitySeconds,
            $rateMaxAgeSeconds,
            'primary',
        );
    }

    private function destinationService(MutableUsdtPersistenceClock $clock): UsdtDestinationWalletService
    {
        return new UsdtDestinationWalletService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(AdministratorPermissionAuthorizer::class),
            $clock,
        );
    }

    private function sourceQuote(
        MutableUsdtPersistenceClock $clock,
        int $userId,
        int $amountIrr,
        string $expiry,
        string $suffix,
    ): QuoteReceipt {
        $offering = $this->offering($amountIrr, true, $suffix);

        return $this->app->make(QuoteService::class)->create(
            'quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $clock->value->modify($expiry),
            ),
            $this->correlation('source-'.$suffix),
        );
    }

    /**
     * @return array{
     *     id:int,
     *     owner_id:int,
     *     dependencies:array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>},
     *     service:PlanOfferingService
     * }
     */
    private function offering(int $basePriceIrr, bool $discountEligible, string $code): array
    {
        $ownerId = $this->administrator(true);
        $dependencies = $this->dependencies();
        $service = $this->app->make(PlanOfferingService::class);
        $created = $service->create(
            $this->definition($dependencies, $basePriceIrr, $discountEligible, $code),
            $this->context($ownerId, 'usdt-offering-create-'.substr(hash('sha256', $code), 0, 16)),
        );

        return [
            'id' => $created->targetId,
            'owner_id' => $ownerId,
            'dependencies' => $dependencies,
            'service' => $service,
        ];
    }

    /** @return array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>} */
    private function dependencies(): array
    {
        $now = now('UTC');
        $suffix = Str::lower(Str::random(6));
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'usdt-category-'.$suffix,
            'name_fa' => 'دسته',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'code' => 'usdt-product-'.$suffix,
            'name_fa' => 'محصول',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'visibility' => 'visible',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $serverId = (int) DB::table('sales_servers')->insertGetId([
            'code' => 'usdt-server-'.$suffix,
            'name_fa' => 'سرور',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'disabled',
            'visibility' => 'hidden',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => 'usdt-connection-'.$suffix,
            'provider_type' => 'fake',
            'name_fa' => 'پنل',
            'name_en' => null,
            'base_url' => 'https://panel.example.com',
            'encrypted_credentials' => 'ciphertext',
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'disabled',
            'last_test_status' => null,
            'last_panel_version' => null,
            'last_capabilities_hash' => null,
            'last_tested_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId,
            'code' => 'usdt-target-'.$suffix,
            'kind' => 'inbound',
            'name_fa' => 'هدف',
            'name_en' => null,
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'usdt-configuration-'.$suffix),
            'configuration_key_version' => 1,
            'state' => 'disabled',
            'capability_status' => 'declared',
            'capability_evidence_hash' => null,
            'capability_verified_at' => null,
            'verified_connection_version' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $profileIds = [];
        foreach (['vless', 'trojan'] as $index => $family) {
            $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
                'code' => 'usdt-profile-'.$family.'-'.$suffix,
                'name_fa' => 'پروفایل',
                'name_en' => null,
                'protocol_family' => $family,
                'transport' => 'ws',
                'security_layer' => 'tls',
                'host' => null,
                'sni' => null,
                'path' => '/usdt-'.$index,
                'port' => 443,
                'flow' => null,
                'state' => 'active',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $profileIds[] = $profileId;
            DB::table('panel_target_protocol_profiles')->insert([
                'panel_service_target_id' => $targetId,
                'panel_protocol_profile_id' => $profileId,
                'customer_selectable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach (['create_service', 'fetch_status'] as $capability) {
            DB::table('panel_target_capabilities')->insert([
                'panel_service_target_id' => $targetId,
                'capability_code' => $capability,
                'verification_status' => 'declared',
                'evidence_hash' => null,
                'verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'usdt-tag-'.$suffix,
            'name_translation_key' => 'customer_tags.usdt',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'product_id' => $productId,
            'server_id' => $serverId,
            'target_id' => $targetId,
            'tag_id' => $tagId,
            'profile_ids' => $profileIds,
        ];
    }

    /**
     * @param  array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>}  $dependencies
     */
    private function definition(
        array $dependencies,
        int $basePriceIrr,
        bool $discountEligible,
        string $code,
    ): PlanOfferingDefinition {
        return new PlanOfferingDefinition(
            $code,
            $dependencies['product_id'],
            null,
            $dependencies['server_id'],
            $dependencies['target_id'],
            new PlanOfferingServiceMode('shared', 'اشتراکی', 'Shared'),
            PlanOfferingAudience::Both,
            PlanOfferingServerSelectionMode::Customer,
            PlanOfferingProtocolSelectionMode::Customer,
            PlanOfferingTagMatchMode::All,
            $basePriceIrr,
            30,
            null,
            3,
            10,
            1,
            3,
            $discountEligible,
            true,
            false,
            false,
            ['normal', 'loyal', 'vip'],
            [$dependencies['tag_id']],
            [
                new OfferingProtocolAssignment($dependencies['profile_ids'][0], true, true),
                new OfferingProtocolAssignment($dependencies['profile_ids'][1], true, false),
            ],
            ['create_service', 'fetch_status'],
            [
                new OfferingOperationPolicy(
                    OfferingOperationCode::Renew,
                    true,
                    true,
                    0,
                    true,
                    'create_service',
                ),
            ],
            [
                new OfferingPackageDefinition(
                    'usdt-extra-10gb',
                    OfferingPackageType::AddData,
                    'ده گیگابایت',
                    '10 GB',
                    500_000,
                    null,
                    10 * 1024 * 1024 * 1024,
                    true,
                    10,
                ),
            ],
        );
    }

    private function administrator(bool $owner): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user('customer'),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function user(string $accountType): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => $accountType,
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $administratorId, string $fingerprint): CatalogChangeContext
    {
        return new CatalogChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'usdt_quote_offering_change',
            'USDT quote foundation test change.',
            $administratorId,
        );
    }

    private function clock(): MutableUsdtPersistenceClock
    {
        $clock = new MutableUsdtPersistenceClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);

        return $clock;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'usdt-rate-quote:'.$suffix);
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization exception.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
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
