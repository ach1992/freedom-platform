<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Promotions\Application\PromotionResolutionRequest;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class MutablePromotionClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PRO-001 REF-001 BUY-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
final class PromotionRuleResolutionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private MutablePromotionClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new MutablePromotionClock(new DateTimeImmutable('2026-08-09 00:00:00', new DateTimeZone('UTC')));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_fixed_rule_resolves_with_audience_and_owned_scope_and_mutation_replay_is_exact(): void
    {
        $owner = $this->administrator(true);
        $customer = $this->customer('customer');
        $offering = $this->offering(1_000_000, true);
        $tagId = $this->tag($customer);
        $service = $this->app->make(PromotionRuleService::class);
        $definition = $this->definition(
            fixedDiscountIrr: 125_000,
            priority: 20,
            tierCode: 'normal',
            tagId: $tagId,
            offeringId: $offering['id'],
            productId: $offering['product_id'],
            serverId: $offering['server_id'],
            action: PromotionAction::Purchase,
        );

        $created = $service->create(
            'promo.mutation.fixed.000001',
            'promo.fixed.scoped',
            PromotionRuleKind::Promotion,
            $definition,
            $this->context($owner, 'create-fixed'),
        );
        self::assertSame(1, $created->version);
        self::assertSame(PromotionRuleState::Active, $created->state);
        self::assertFalse($created->replayed);
        self::assertSame(64, strlen($created->configurationHash));

        $replay = $service->create(
            'promo.mutation.fixed.000001',
            'promo.fixed.scoped',
            PromotionRuleKind::Promotion,
            $definition,
            $this->context($owner, 'create-fixed-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($created->versionId, $replay->versionId);
        self::assertSame($created->configurationHash, $replay->configurationHash);

        $resolution = $service->resolve(
            $this->request('promo.resolve.fixed.000001', $customer, $offering['id'], 1_000_000),
            $this->context($owner, 'resolve-fixed'),
        );
        self::assertTrue($resolution->matched());
        self::assertSame(125_000, $resolution->discountIrr);
        self::assertSame('promo.fixed.scoped', $resolution->ruleCode);
        self::assertSame(1, $resolution->ruleVersion);
        self::assertSame($created->configurationHash, $resolution->ruleConfigurationHash);
        self::assertSame(0, DB::table('ledger_transactions')->count());
        self::assertSame(0, DB::table('payment_intents')->count());

        $this->assertRuntimeMessage(
            'Promotion mutation key conflict.',
            fn (): mixed => $service->create(
                'promo.mutation.fixed.000001',
                'promo.fixed.scoped',
                PromotionRuleKind::Promotion,
                $this->definition(fixedDiscountIrr: 125_001),
                $this->context($owner, 'create-fixed-conflict'),
            ),
        );
    }

    public function test_percentage_minimum_cap_window_limits_first_purchase_and_referral_inputs_are_deterministic(): void
    {
        $owner = $this->administrator(true);
        $customer = $this->customer('customer');
        $offering = $this->offering(1_000_000, true);
        $service = $this->app->make(PromotionRuleService::class);
        $service->create(
            'promo.mutation.referral.000001',
            'referral.welcome.25pct',
            PromotionRuleKind::Referral,
            new PromotionRuleDefinition(
                PromotionRuleState::Active,
                50,
                PromotionDiscountType::Percentage,
                null,
                2500,
                500_000,
                100_000,
                $this->clock->value->modify('-1 hour'),
                $this->clock->value->modify('+1 hour'),
                10,
                2,
                true,
                PromotionAudience::Customers,
                null,
                null,
                $offering['id'],
                null,
                null,
                PromotionAction::Purchase,
                'referral.partner-A',
            ),
            $this->context($owner, 'create-referral'),
        );

        $matched = $service->resolve(
            new PromotionResolutionRequest(
                'promo.resolve.referral.000001',
                $customer,
                $offering['id'],
                PromotionAction::Purchase,
                1_000_000,
                5,
                1,
                false,
                'referral.partner-A',
            ),
            $this->context($owner, 'resolve-referral'),
        );
        self::assertSame(100_000, $matched->discountIrr);
        self::assertSame(PromotionRuleKind::Referral, $matched->ruleKind);

        foreach ([
            ['promo.resolve.referral.min', 499_999, 5, 1, false, 'referral.partner-A'],
            ['promo.resolve.referral.total', 1_000_000, 10, 1, false, 'referral.partner-A'],
            ['promo.resolve.referral.user', 1_000_000, 5, 2, false, 'referral.partner-A'],
            ['promo.resolve.referral.prior', 1_000_000, 5, 1, true, 'referral.partner-A'],
            ['promo.resolve.referral.identity', 1_000_000, 5, 1, false, 'referral.other'],
        ] as [$key, $price, $total, $userUses, $prior, $source]) {
            $receipt = $service->resolve(
                new PromotionResolutionRequest($key, $customer, $offering['id'], PromotionAction::Purchase, $price, $total, $userUses, $prior, $source),
                $this->context($owner, $key),
            );
            self::assertFalse($receipt->matched());
            self::assertSame(0, $receipt->discountIrr);
        }

        $this->clock->value = $this->clock->value->modify('+2 hours');
        $expired = $service->resolve(
            new PromotionResolutionRequest('promo.resolve.referral.expired', $customer, $offering['id'], PromotionAction::Purchase, 1_000_000, 0, 0, false, 'referral.partner-A'),
            $this->context($owner, 'resolve-expired'),
        );
        self::assertFalse($expired->matched());
    }

    public function test_priority_is_explicit_and_equal_priority_match_fails_closed_without_row_order_dependency(): void
    {
        $owner = $this->administrator(true);
        $customer = $this->customer('customer');
        $offering = $this->offering(1_000_000, true);
        $service = $this->app->make(PromotionRuleService::class);

        $service->create('promo.mutation.low.000001', 'promo.low', PromotionRuleKind::Promotion, $this->definition(50_000, 10), $this->context($owner, 'low'));
        $service->create('promo.mutation.high.000001', 'promo.high', PromotionRuleKind::Promotion, $this->definition(75_000, 20), $this->context($owner, 'high'));
        $winner = $service->resolve($this->request('promo.resolve.priority.000001', $customer, $offering['id'], 1_000_000), $this->context($owner, 'priority'));
        self::assertSame('promo.high', $winner->ruleCode);
        self::assertSame(75_000, $winner->discountIrr);

        $service->create('promo.mutation.tie.000001', 'promo.tie', PromotionRuleKind::Promotion, $this->definition(60_000, 20), $this->context($owner, 'tie'));
        $this->assertRuntimeMessage(
            'Promotion rule resolution is ambiguous.',
            fn (): mixed => $service->resolve($this->request('promo.resolve.tie.000001', $customer, $offering['id'], 1_000_000), $this->context($owner, 'resolve-tie')),
        );
        self::assertFalse(DB::table('pricing_rule_resolutions')->where('resolution_key', 'promo.resolve.tie.000001')->exists());
    }

    public function test_exact_resolution_replay_is_stable_after_rule_disable_and_conflicting_reuse_is_rejected(): void
    {
        $owner = $this->administrator(true);
        $customer = $this->customer('customer');
        $offering = $this->offering(1_000_000, true);
        $service = $this->app->make(PromotionRuleService::class);
        $service->create('promo.mutation.stable.000001', 'promo.stable', PromotionRuleKind::Promotion, $this->definition(90_000), $this->context($owner, 'stable-create'));

        $request = $this->request('promo.resolve.stable.000001', $customer, $offering['id'], 1_000_000);
        $accepted = $service->resolve($request, $this->context($owner, 'stable-resolve'));
        self::assertSame(90_000, $accepted->discountIrr);
        self::assertSame(1, $accepted->ruleVersion);

        $service->revise(
            'promo.mutation.stable.000002',
            'promo.stable',
            $this->definition(90_000, state: PromotionRuleState::Disabled),
            $this->context($owner, 'stable-disable'),
        );

        $replay = $service->resolve($request, $this->context($owner, 'stable-resolve'));
        self::assertTrue($replay->replayed);
        self::assertSame($accepted->resolutionId, $replay->resolutionId);
        self::assertSame(90_000, $replay->discountIrr);
        self::assertSame(1, $replay->ruleVersion);
        self::assertSame($accepted->configurationSnapshotHash, $replay->configurationSnapshotHash);

        $newResolution = $service->resolve($this->request('promo.resolve.stable.000002', $customer, $offering['id'], 1_000_000), $this->context($owner, 'stable-new'));
        self::assertFalse($newResolution->matched());
        self::assertSame(0, $newResolution->discountIrr);

        $this->assertRuntimeMessage(
            'Promotion resolution key conflict.',
            fn (): mixed => $service->resolve($this->request('promo.resolve.stable.000001', $customer, $offering['id'], 999_999), $this->context($owner, 'stable-resolve')),
        );
    }

    public function test_execution_time_authorization_allows_seeded_roles_and_denies_unauthorized_administrators(): void
    {
        $sales = $this->administrator(false, 'sales_content');
        $support = $this->administrator(false, 'support');
        $finance = $this->administrator(false, 'finance');
        $customer = $this->customer('customer');
        $offering = $this->offering(500_000, true);
        $service = $this->app->make(PromotionRuleService::class);

        $created = $service->create('promo.mutation.auth.000001', 'promo.auth', PromotionRuleKind::Promotion, $this->definition(25_000), $this->context($sales, 'auth-create'));
        self::assertSame(1, $created->version);

        $this->assertAuthorizationDenied(fn (): mixed => $service->create(
            'promo.mutation.auth.000002',
            'promo.auth.denied',
            PromotionRuleKind::Promotion,
            $this->definition(25_000),
            $this->context($support, 'auth-denied'),
        ));

        $resolved = $service->resolve($this->request('promo.resolve.auth.000001', $customer, $offering['id'], 500_000), $this->context($finance, 'auth-resolve'));
        self::assertSame(25_000, $resolved->discountIrr);
        $this->assertAuthorizationDenied(fn (): mixed => $service->resolve(
            $this->request('promo.resolve.auth.000002', $customer, $offering['id'], 500_000),
            $this->context($support, 'auth-resolve-denied'),
        ));
    }

    public function test_invalid_configuration_and_free_order_policy_fail_closed(): void
    {
        $this->assertInvalidArgument(fn (): PromotionRuleDefinition => new PromotionRuleDefinition(
            PromotionRuleState::Active,
            0,
            PromotionDiscountType::Percentage,
            null,
            10001,
            0,
            null,
            null,
            null,
            null,
            null,
            false,
            PromotionAudience::Both,
        ));

        $owner = $this->administrator(true);
        $customer = $this->customer('customer');
        $offering = $this->offering(100_000, true);
        $service = $this->app->make(PromotionRuleService::class);
        $service->create('promo.mutation.free.000001', 'promo.free.denied', PromotionRuleKind::Promotion, $this->definition(100_000), $this->context($owner, 'free-create'));
        $this->assertDomainMessage(
            'Promotion rule would make the price fully free without explicit permission.',
            fn (): mixed => $service->resolve($this->request('promo.resolve.free.000001', $customer, $offering['id'], 100_000), $this->context($owner, 'free-resolve')),
        );
        self::assertFalse(DB::table('pricing_rule_resolutions')->where('resolution_key', 'promo.resolve.free.000001')->exists());
    }

    public function test_mariadb_guards_enforce_uniqueness_foreign_keys_checks_hashes_and_immutability(): void
    {
        $owner = $this->administrator(true);
        $customer = $this->customer('customer');
        $offering = $this->offering(1_000_000, true);
        $service = $this->app->make(PromotionRuleService::class);
        $version = $service->create('promo.mutation.db.000001', 'promo.db', PromotionRuleKind::Promotion, $this->definition(10_000), $this->context($owner, 'db-create'));
        $resolution = $service->resolve($this->request('promo.resolve.db.000001', $customer, $offering['id'], 1_000_000), $this->context($owner, 'db-resolve'));

        $this->assertQueryRejected(static fn (): int => DB::table('pricing_rules')->where('id', $version->ruleId)->update(['rule_code' => 'forged']));
        $this->assertQueryRejected(static fn (): int => DB::table('pricing_rule_versions')->where('id', $version->versionId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('pricing_rule_resolutions')->where('id', $resolution->resolutionId)->update(['discount_irr' => 1]));
        $this->assertQueryRejected(static fn (): bool => DB::table('pricing_rules')->insert([
            'public_id' => (string) Str::ulid(),
            'rule_code' => 'promo.db',
            'kind' => 'promotion',
            'created_at' => now('UTC'),
        ]));

        $stored = DB::table('pricing_rule_versions')->where('id', $version->versionId)->first();
        self::assertNotNull($stored);
        /** @var array<string, mixed> $invalid */
        $invalid = (array) $stored;
        unset($invalid['id']);
        $invalid['mutation_key'] = 'promo.mutation.db.invalid-check';
        $invalid['mutation_payload_hash'] = hash('sha256', 'invalid-check');
        $invalid['version'] = 2;
        $invalid['discount_type'] = 'invalid';
        $this->assertQueryRejected(static fn (): bool => DB::table('pricing_rule_versions')->insert($invalid));

        $invalid['mutation_key'] = 'promo.mutation.db.invalid-hash';
        $invalid['discount_type'] = 'fixed';
        $invalid['configuration_hash'] = str_repeat('0', 64);
        $this->assertQueryRejected(static fn (): bool => DB::table('pricing_rule_versions')->insert($invalid));

        $invalid['mutation_key'] = 'promo.mutation.db.invalid-fk';
        $invalid['configuration_hash'] = $stored->configuration_hash;
        $invalid['pricing_rule_id'] = 999999999;
        $invalid['version'] = 1;
        $this->assertQueryRejected(static fn (): bool => DB::table('pricing_rule_versions')->insert($invalid));

        self::assertSame(1, DB::table('pricing_rules')->count());
        self::assertSame(1, DB::table('pricing_rule_versions')->count());
        self::assertSame(1, DB::table('pricing_rule_resolutions')->count());
    }

    private function definition(
        int $fixedDiscountIrr = 50_000,
        int $priority = 10,
        PromotionRuleState $state = PromotionRuleState::Active,
        ?string $tierCode = null,
        ?int $tagId = null,
        ?int $offeringId = null,
        ?int $productId = null,
        ?int $serverId = null,
        ?PromotionAction $action = null,
    ): PromotionRuleDefinition {
        return new PromotionRuleDefinition(
            $state,
            $priority,
            PromotionDiscountType::Fixed,
            $fixedDiscountIrr,
            null,
            0,
            null,
            null,
            null,
            null,
            null,
            false,
            PromotionAudience::Both,
            $tierCode,
            $tagId,
            $offeringId,
            $productId,
            $serverId,
            $action,
        );
    }

    private function request(string $key, int $userId, int $offeringId, int $priceIrr): PromotionResolutionRequest
    {
        return new PromotionResolutionRequest($key, $userId, $offeringId, PromotionAction::Purchase, $priceIrr, 0, 0, false);
    }

    /** @return array{id:int,product_id:int,server_id:int} */
    private function offering(int $priceIrr, bool $discountEligible): array
    {
        $now = now('UTC');
        $suffix = Str::lower(Str::random(8));
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null, 'code' => 'promo-cat-'.$suffix, 'name_fa' => 'دسته', 'name_en' => null,
            'description_fa' => null, 'description_en' => null, 'state' => 'active', 'sort_order' => 0,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'code' => 'promo-product-'.$suffix, 'name_fa' => 'محصول', 'name_en' => null,
            'description_fa' => null, 'description_en' => null, 'state' => 'active', 'visibility' => 'visible',
            'sort_order' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $serverId = (int) DB::table('sales_servers')->insertGetId([
            'code' => 'promo-server-'.$suffix, 'name_fa' => 'سرور', 'name_en' => null, 'description_fa' => null,
            'description_en' => null, 'state' => 'disabled', 'visibility' => 'hidden', 'sort_order' => 0,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => 'promo-connection-'.$suffix, 'provider_type' => 'fake', 'name_fa' => 'پنل', 'name_en' => null,
            'base_url' => 'https://panel.example.com', 'encrypted_credentials' => 'ciphertext', 'credential_key_version' => 1,
            'tls_policy' => 'system_ca', 'custom_ca_disk' => null, 'custom_ca_path' => null, 'certificate_pin_sha256' => null,
            'network_policy' => 'public_only', 'state' => 'disabled', 'last_test_status' => null, 'last_panel_version' => null,
            'last_capabilities_hash' => null, 'last_tested_at' => null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId, 'code' => 'promo-target-'.$suffix, 'kind' => 'inbound',
            'name_fa' => 'هدف', 'name_en' => null, 'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'promo-target-'.$suffix), 'configuration_key_version' => 1,
            'state' => 'disabled', 'capability_status' => 'declared', 'capability_evidence_hash' => null,
            'capability_verified_at' => null, 'verified_connection_version' => null, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $offeringId = (int) DB::table('plan_offerings')->insertGetId([
            'code' => 'promo-offering-'.$suffix, 'product_id' => $productId, 'variant_id' => null,
            'sales_server_id' => $serverId, 'panel_service_target_id' => $targetId, 'service_mode_code' => 'standard',
            'service_mode_label_fa' => 'استاندارد', 'service_mode_label_en' => 'Standard', 'audience' => 'both',
            'server_selection_mode' => 'system_selects', 'protocol_selection_mode' => 'fixed', 'tag_match_mode' => 'all',
            'base_price_irr' => $priceIrr, 'duration_days' => 30, 'data_allowance_bytes' => null, 'device_limit' => null,
            'sort_order' => 0, 'min_purchase_quantity' => 1, 'max_purchase_quantity' => 1,
            'discount_eligible' => $discountEligible, 'auto_renew_allowed' => false, 'custom_plan_allowed' => false,
            'trial_allowed' => false, 'state' => 'draft', 'visibility' => 'hidden', 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['id' => $offeringId, 'product_id' => $productId, 'server_id' => $serverId];
    }

    private function customer(string $accountType): int
    {
        $now = now('UTC');
        $userId = $this->user($accountType);
        $tierId = DB::table('customer_tiers')->where('code', 'normal')->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => (int) $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => 'unverified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function tag(int $userId): int
    {
        $now = now('UTC');
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'promo-tag-'.Str::lower(Str::random(8)),
            'name_translation_key' => 'promo.test.tag',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => $tagId,
            'assigned_by_administrator_id' => null,
            'assigned_at' => $now,
            'removed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $tagId;
    }

    private function administrator(bool $owner = false, ?string $roleCode = null): int
    {
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user('customer'),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($roleCode !== null) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            DB::table('administrator_role_assignments')->insert([
                'administrator_id' => $administratorId,
                'role_id' => (int) $roleId,
                'granted_by_administrator_id' => null,
                'granted_at' => $now,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $administratorId;
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

    private function context(int $administratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            hash('sha256', 'promotion-request:'.$suffix),
            substr(hash('sha256', 'promotion-correlation:'.$suffix), 0, 64),
            'promotion_test',
            'Promotion rule resolution test reason.',
            $administratorId,
        );
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

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
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

    private function assertInvalidArgument(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected invalid argument exception.');
        } catch (InvalidArgumentException) {
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
