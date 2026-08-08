<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
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
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
    }

    public function test_quote_snapshots_integer_irr_base_price_and_exact_replays_without_financial_effect(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $offering = $this->offering(1_000_000, true, 'quote-base');
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            null,
            0,
            $clock->value->modify('+30 minutes'),
        );

        $quote = $service->create(
            'quote.base.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('base-create'),
        );

        self::assertSame(1_000_000, $quote->basePriceIrr);
        self::assertSame(1_000_000, $quote->effectivePriceIrr);
        self::assertSame(0, $quote->discountIrr);
        self::assertSame(1_000_000, $quote->finalPriceIrr);
        self::assertSame('IRR', $quote->currency);
        self::assertSame(QuoteOverrideSource::None, $quote->overrideSource);
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
        self::assertSame($quote->configurationSnapshotHash, hash('sha256', $snapshotJson));

        $replay = $service->create(
            'quote.base.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('base-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($quote->quoteId, $replay->quoteId);
        self::assertSame($quote->quotePublicId, $replay->quotePublicId);
        self::assertSame($quote->configurationSnapshotHash, $replay->configurationSnapshotHash);
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_same_quote_key_with_changed_pricing_or_validity_conflicts_without_overwrite(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $offering = $this->offering(1_000_000, true, 'quote-conflict');
        $service = $this->app->make(QuoteService::class);
        $original = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            null,
            0,
            $clock->value->modify('+20 minutes'),
        );
        $service->create('quote.conflict.000001', $userId, $offering['id'], $original, $this->correlation('conflict-create'));

        $changed = new QuotePricingInput(
            QuoteOverrideSource::Account,
            'account.manual',
            900_000,
            null,
            0,
            $clock->value->modify('+20 minutes'),
        );
        $this->assertRuntimeMessage(
            'Quote key conflict.',
            fn (): mixed => $service->create(
                'quote.conflict.000001',
                $userId,
                $offering['id'],
                $changed,
                $this->correlation('conflict-pricing'),
            ),
        );

        $changedValidity = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            null,
            0,
            $clock->value->modify('+21 minutes'),
        );
        $this->assertRuntimeMessage(
            'Quote key conflict.',
            fn (): mixed => $service->create(
                'quote.conflict.000001',
                $userId,
                $offering['id'],
                $changedValidity,
                $this->correlation('conflict-validity'),
            ),
        );
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1_000_000, (int) DB::table('quotes')->value('final_price_irr'));
    }

    public function test_explicit_override_precedes_discount_and_invalid_discount_fails_closed(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $offering = $this->offering(1_000_000, true, 'quote-override');
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(
            QuoteOverrideSource::Account,
            'account.vip-price',
            900_000,
            'discount.manual-100k',
            100_000,
            $clock->value->modify('+30 minutes'),
        );

        $quote = $service->create(
            'quote.override.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('override-create'),
        );
        self::assertSame(1_000_000, $quote->basePriceIrr);
        self::assertSame(900_000, $quote->overridePriceIrr);
        self::assertSame(900_000, $quote->effectivePriceIrr);
        self::assertSame(100_000, $quote->discountIrr);
        self::assertSame(800_000, $quote->finalPriceIrr);
        self::assertSame('account.vip-price', $quote->overrideReferenceCode);
        self::assertSame('discount.manual-100k', $quote->discountReferenceCode);

        $tooLargeDiscount = new QuotePricingInput(
            QuoteOverrideSource::Account,
            'account.vip-price',
            90_000,
            'discount.too-large',
            90_001,
            $clock->value->modify('+30 minutes'),
        );
        $this->assertDomainMessage(
            'Quote discount cannot exceed the effective price.',
            fn (): mixed => $service->create(
                'quote.override.000002',
                $userId,
                $offering['id'],
                $tooLargeDiscount,
                $this->correlation('override-too-large'),
            ),
        );
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_discount_requires_offering_eligibility_and_pricing_input_rejects_invalid_shapes(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $offering = $this->offering(500_000, false, 'quote-no-discount');
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            'discount.not-eligible',
            1,
            $clock->value->modify('+10 minutes'),
        );
        $this->assertDomainMessage(
            'Plan offering does not allow a quote discount.',
            fn (): mixed => $service->create(
                'quote.discount.000001',
                $userId,
                $offering['id'],
                $pricing,
                $this->correlation('discount-not-eligible'),
            ),
        );

        $this->assertInvalidArgumentMessage(
            'Quote discount must be non-negative integer IRR.',
            fn (): mixed => new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                -1,
                $clock->value->modify('+10 minutes'),
            ),
        );
        $this->assertInvalidArgumentMessage(
            'Quote without an override cannot contain override data.',
            fn (): mixed => new QuotePricingInput(
                QuoteOverrideSource::None,
                'unexpected',
                1,
                null,
                0,
                $clock->value->modify('+10 minutes'),
            ),
        );
        self::assertSame(0, DB::table('quotes')->count());
    }

    public function test_current_tier_and_agent_references_are_required_for_resolved_override_inputs(): void
    {
        $clock = $this->clock();
        $offering = $this->offering(1_000_000, true, 'quote-subject-overrides');
        $service = $this->app->make(QuoteService::class);

        $customerId = $this->user('customer');
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
            new QuotePricingInput(
                QuoteOverrideSource::Tier,
                'normal',
                950_000,
                null,
                0,
                $clock->value->modify('+30 minutes'),
            ),
            $this->correlation('tier-create'),
        );
        self::assertSame(QuoteOverrideSource::Tier, $tierQuote->overrideSource);
        self::assertSame('normal', $tierQuote->overrideReferenceCode);
        self::assertSame(950_000, $tierQuote->finalPriceIrr);

        $this->assertDomainMessage(
            'Tier quote override reference is not current.',
            fn (): mixed => $service->create(
                'quote.tier.000002',
                $customerId,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::Tier,
                    'vip',
                    900_000,
                    null,
                    0,
                    $clock->value->modify('+30 minutes'),
                ),
                $this->correlation('tier-stale'),
            ),
        );

        $agentId = $this->user('agent');
        $applicationId = (int) DB::table('agent_applications')->insertGetId([
            'customer_id' => $agentId,
            'active_customer_id' => null,
            'state' => 'approved',
            'claimed_by_administrator_id' => null,
            'decided_by_administrator_id' => null,
            'decision_reason_code' => 'approved_for_quote_test',
            'decision_reason' => null,
            'application_version' => 1,
            'submitted_at' => now('UTC'),
            'claimed_at' => null,
            'decided_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('agent_profiles')->insert([
            'user_id' => $agentId,
            'status' => 'active',
            'pricing_profile_code' => 'agent-standard',
            'approved_application_id' => $applicationId,
            'approved_by_administrator_id' => null,
            'approved_at' => now('UTC'),
            'suspended_at' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $agentQuote = $service->create(
            'quote.agent.000001',
            $agentId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::Agent,
                'agent-standard',
                875_000,
                null,
                0,
                $clock->value->modify('+30 minutes'),
            ),
            $this->correlation('agent-create'),
        );
        self::assertSame(QuoteOverrideSource::Agent, $agentQuote->overrideSource);
        self::assertSame('agent-standard', $agentQuote->overrideReferenceCode);
        self::assertSame(875_000, $agentQuote->finalPriceIrr);

        $this->assertDomainMessage(
            'Agent quote override reference is not current.',
            fn (): mixed => $service->create(
                'quote.agent.000002',
                $agentId,
                $offering['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::Agent,
                    'agent-other',
                    800_000,
                    null,
                    0,
                    $clock->value->modify('+30 minutes'),
                ),
                $this->correlation('agent-stale'),
            ),
        );
        self::assertSame(2, DB::table('quotes')->count());
    }

    public function test_quote_remains_stable_after_later_offering_price_change_and_new_quote_uses_new_configuration(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $offering = $this->offering(1_000_000, true, 'quote-stability');
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            null,
            0,
            $clock->value->modify('+30 minutes'),
        );
        $first = $service->create(
            'quote.stability.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('stability-first'),
        );

        $offering['service']->update(
            $offering['id'],
            1,
            $this->definition($offering['dependencies'], 1_250_000, true, 'quote-stability'),
            $this->context($offering['owner_id'], 'quote-offering-update-000001'),
        );

        $replay = $service->create(
            'quote.stability.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('stability-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame(1_000_000, $replay->basePriceIrr);
        self::assertSame($first->offeringConfigurationHash, $replay->offeringConfigurationHash);

        $second = $service->create(
            'quote.stability.000002',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('stability-second'),
        );
        self::assertFalse($second->replayed);
        self::assertSame(1_250_000, $second->basePriceIrr);
        self::assertSame(2, $second->offeringVersion);
        self::assertNotSame($first->offeringConfigurationHash, $second->offeringConfigurationHash);
        self::assertSame(2, DB::table('quotes')->count());
    }

    public function test_expired_quote_is_not_current_but_exact_creation_replay_remains_historical(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $offering = $this->offering(250_000, true, 'quote-expiry');
        $service = $this->app->make(QuoteService::class);
        $pricing = new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            null,
            0,
            $clock->value->modify('+5 minutes'),
        );
        $quote = $service->create(
            'quote.expiry.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('expiry-create'),
        );

        $clock->value = $clock->value->modify('+5 minutes');
        $this->assertRuntimeMessage(
            'Quote has expired.',
            fn (): mixed => $service->current($quote->quotePublicId),
        );

        $historicalReplay = $service->create(
            'quote.expiry.000001',
            $userId,
            $offering['id'],
            $pricing,
            $this->correlation('expiry-replay'),
        );
        self::assertTrue($historicalReplay->replayed);
        self::assertSame($quote->quoteId, $historicalReplay->quoteId);
        self::assertSame(1, DB::table('quotes')->count());
    }

    public function test_database_guards_keep_quote_immutable_and_reject_forged_configuration_snapshot(): void
    {
        $clock = $this->clock();
        $userId = $this->user('customer');
        $offering = $this->offering(700_000, true, 'quote-db-guard');
        $quote = $this->app->make(QuoteService::class)->create(
            'quote.guard.000001',
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $clock->value->modify('+30 minutes'),
            ),
            $this->correlation('guard-create'),
        );

        $this->assertQueryRejected(static fn (): int => DB::table('quotes')
            ->where('id', $quote->quoteId)
            ->update(['final_price_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('quotes')
            ->where('id', $quote->quoteId)
            ->delete());

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
        $ownerId = $this->administrator();
        $dependencies = $this->dependencies();
        $service = $this->app->make(PlanOfferingService::class);
        $created = $service->create(
            $this->definition($dependencies, $basePriceIrr, $discountEligible, $code),
            $this->context($ownerId, 'quote-offering-create-'.substr(hash('sha256', $code), 0, 16)),
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
            'code' => 'quote-category-'.$suffix,
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
            'code' => 'quote-product-'.$suffix,
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
            'code' => 'quote-server-'.$suffix,
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
            'code' => 'quote-connection-'.$suffix,
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
            'code' => 'quote-target-'.$suffix,
            'kind' => 'inbound',
            'name_fa' => 'هدف',
            'name_en' => null,
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'quote-configuration-'.$suffix),
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
                'code' => 'quote-profile-'.$family.'-'.$suffix,
                'name_fa' => 'پروفایل',
                'name_en' => null,
                'protocol_family' => $family,
                'transport' => 'ws',
                'security_layer' => 'tls',
                'host' => null,
                'sni' => null,
                'path' => '/quote-'.$index,
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
            'code' => 'quote-tag-'.$suffix,
            'name_translation_key' => 'customer_tags.quote',
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
                    'quote-extra-10gb',
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

    private function administrator(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user('customer'),
            'status' => 'active',
            'is_owner' => true,
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
            'quote_offering_change',
            'Quote pricing snapshot test change.',
            $administratorId,
        );
    }

    private function clock(): MutableQuoteClock
    {
        $clock = new MutableQuoteClock(new DateTimeImmutable('2026-08-08T12:00:00+00:00'));
        $this->app->instance(Clock::class, $clock);

        return $clock;
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
