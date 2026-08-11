<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Application\PlanOfferingRouteSelector;
use App\Modules\Catalog\Application\RouteOperationalVerifier;
use App\Modules\Catalog\Application\RouteSelectionContext;
use App\Modules\Catalog\Application\RouteSelectionRequest;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use App\Modules\Catalog\Domain\RouteSelectionActor;
use App\Modules\Catalog\Infrastructure\DatabaseRouteOperationalVerifier;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement CAT-002 CAT-004 CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
final class PlanOfferingRouteSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
    }

    public function test_policy_is_replay_safe_and_hybrid_selection_falls_back_with_atomic_capacity_hold(): void
    {
        $scenario = $this->scenario('hybrid');
        $policyService = $this->app->make(PlanOfferingRoutePolicyService::class);
        $definition = $this->policyDefinition($scenario);
        $context = $this->catalogContext($scenario['owner_id'], 'offering-route-policy-create-001');
        $created = $policyService->create($scenario['offering_id'], $definition, $context);
        $replay = $policyService->create($scenario['offering_id'], $definition, $context);
        self::assertTrue($created->changed);
        self::assertTrue($replay->replayed);
        self::assertSame(1, DB::table('plan_offering_route_policies')->count());
        self::assertSame(2, DB::table('plan_offering_routes')->count());
        self::assertSame(1, DB::table('plan_offering_route_policy_histories')->count());

        /** @var list<object{id: int|string, panel_service_target_id: int|string}> $routes */
        $routes = DB::table('plan_offering_routes')->orderBy('priority')->get(['id', 'panel_service_target_id'])->all();
        $primaryRouteId = (int) $routes[0]->id;
        $fallbackRouteId = (int) $routes[1]->id;
        $this->fillPrimaryCapacity($scenario['primary_capacity_id']);

        $this->app->instance(RouteOperationalVerifier::class, new class implements RouteOperationalVerifier
        {
            public function assertOperational(
                Connection $connection,
                int $offeringId,
                int $salesServerId,
                int $serviceTargetId,
                int $protocolProfileId,
            ): void {}
        });
        $this->app->forgetInstance(PlanOfferingRouteSelector::class);
        $selector = $this->app->make(PlanOfferingRouteSelector::class);
        $selectionContext = new RouteSelectionContext(
            'route-selection-hybrid-command-001',
            'route-selection-correlation-001',
            'purchase',
            'capacity_hold',
        );
        $request = new RouteSelectionRequest(
            $scenario['offering_id'],
            $scenario['user_id'],
            RouteSelectionActor::Customer,
            $primaryRouteId,
            $scenario['profile_id'],
            1,
            (new DateTimeImmutable('now'))->modify('+10 minutes'),
        );
        $selected = $selector->select($request, $selectionContext);
        $selectionReplay = $selector->select($request, $selectionContext);
        self::assertSame($fallbackRouteId, $selected->routeId);
        self::assertSame($scenario['fallback_target_id'], $selected->serviceTargetId);
        self::assertTrue($selected->fallbackUsed);
        self::assertNotNull($selected->disclosureFa);
        self::assertTrue($selectionReplay->replayed);
        self::assertSame($selected->selectionId, $selectionReplay->selectionId);
        self::assertSame(1, DB::table('plan_offering_route_selections')->count());
        self::assertSame(1, (int) DB::table('panel_target_capacities')
            ->where('id', $scenario['fallback_capacity_id'])->value('held_units'));

        try {
            $selector->select(
                new RouteSelectionRequest(
                    $scenario['offering_id'],
                    $scenario['user_id'],
                    RouteSelectionActor::Customer,
                    $primaryRouteId,
                    $scenario['profile_id'],
                    2,
                    $request->expiresAt,
                ),
                $selectionContext,
            );
            self::fail('Expected route selection command conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Route selection command key conflict.', $exception->getMessage());
        }

        try {
            DB::table('plan_offering_route_selections')->where('id', $selected->selectionId)->update(['units' => 2]);
            self::fail('Expected immutable route selection rejection.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('plan_offering_route_selections')->count());
        }
    }

    public function test_system_mode_rejects_requested_route(): void
    {
        $scenario = $this->scenario('system_selects');
        $policy = $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $scenario['offering_id'],
            $this->systemPolicyDefinition($scenario),
            $this->catalogContext($scenario['owner_id'], 'offering-route-policy-system-001'),
        );
        $routeId = (int) DB::table('plan_offering_routes')
            ->where('plan_offering_route_policy_id', $policy->targetId)
            ->where('priority', 0)
            ->value('id');
        $this->app->instance(RouteOperationalVerifier::class, new class implements RouteOperationalVerifier
        {
            public function assertOperational(
                Connection $connection,
                int $offeringId,
                int $salesServerId,
                int $serviceTargetId,
                int $protocolProfileId,
            ): void {}
        });
        $this->app->forgetInstance(PlanOfferingRouteSelector::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('System-selected offering does not accept a requested route.');
        $this->app->make(PlanOfferingRouteSelector::class)->select(
            new RouteSelectionRequest(
                $scenario['offering_id'],
                $scenario['user_id'],
                RouteSelectionActor::Customer,
                $routeId,
                $scenario['profile_id'],
                1,
                (new DateTimeImmutable('now'))->modify('+10 minutes'),
            ),
            new RouteSelectionContext(
                'route-selection-system-command-001',
                'route-selection-correlation-002',
                'purchase',
                'capacity_hold',
            ),
        );
    }

    public function test_production_verifier_is_fail_closed_without_adapter_evidence(): void
    {
        $scenario = $this->scenario('system_selects');
        $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $scenario['offering_id'],
            $this->systemPolicyDefinition($scenario),
            $this->catalogContext($scenario['owner_id'], 'offering-route-policy-strict-001'),
        );

        $this->expectException(RouteCandidateUnavailable::class);
        (new DatabaseRouteOperationalVerifier)->assertOperational(
            $this->app->make(DatabaseManager::class)->connection(),
            $scenario['offering_id'],
            $scenario['primary_server_id'],
            $scenario['primary_target_id'],
            $scenario['profile_id'],
        );
    }

    public function test_unauthorized_administrator_cannot_create_route_policy(): void
    {
        $scenario = $this->scenario('hybrid');
        $this->expectException(AuthorizationException::class);
        $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition($scenario),
            $this->catalogContext($scenario['administrator_id'], 'offering-route-policy-denied-001'),
        );
    }

    /** @return array<string, int> */
    private function scenario(string $selectionMode): array
    {
        $now = now('UTC');
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator(false);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(), 'account_type' => 'customer', 'account_status' => 'active',
            'locale' => 'fa', 'first_seen_at' => $now, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $tierId = (int) DB::table('customer_tiers')->where('code', 'normal')->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId, 'current_tier_id' => $tierId, 'tier_locked' => false,
            'tier_lock_reason_code' => null, 'phone_verification_status' => 'verified',
            'identity_verification_status' => 'verified', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'route-eligible-'.Str::lower(Str::random(6)),
            'name_translation_key' => 'customer_tags.route_eligible', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId, 'tag_id' => $tagId, 'assigned_by_administrator_id' => $ownerId,
            'assigned_at' => $now, 'removed_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null, 'code' => 'route-category-'.Str::lower(Str::random(6)),
            'name_fa' => 'Category', 'name_en' => null, 'description_fa' => null, 'description_en' => null,
            'state' => 'active', 'sort_order' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'code' => 'route-product-'.Str::lower(Str::random(6)),
            'name_fa' => 'Product', 'name_en' => null, 'description_fa' => null, 'description_en' => null,
            'state' => 'active', 'visibility' => 'visible', 'sort_order' => 0, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $primaryServerId = $this->server('route-primary', $now);
        $fallbackServerId = $this->server('route-fallback', $now);
        $primaryTargetId = $this->target('route-primary', $now);
        $fallbackTargetId = $this->target('route-fallback', $now);
        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'route-profile-'.Str::lower(Str::random(6)), 'name_fa' => 'Profile', 'name_en' => null,
            'protocol_family' => 'vless', 'transport' => 'ws', 'security_layer' => 'tls', 'host' => null,
            'sni' => null, 'path' => '/route', 'port' => 443, 'flow' => null, 'state' => 'active',
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([$primaryTargetId, $fallbackTargetId] as $targetId) {
            DB::table('panel_target_protocol_profiles')->insert([
                'panel_service_target_id' => $targetId, 'panel_protocol_profile_id' => $profileId,
                'customer_selectable' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('panel_target_capabilities')->insert([
                'panel_service_target_id' => $targetId, 'capability_code' => 'create_service',
                'verification_status' => 'declared', 'evidence_hash' => null, 'verified_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $offeringId = (int) DB::table('plan_offerings')->insertGetId([
            'code' => 'route-offering-'.Str::lower(Str::random(6)),
            'product_id' => $productId, 'variant_id' => null, 'sales_server_id' => $primaryServerId,
            'panel_service_target_id' => $primaryTargetId, 'service_mode_code' => 'shared',
            'service_mode_label_fa' => 'Shared', 'service_mode_label_en' => null, 'audience' => 'both',
            'server_selection_mode' => $selectionMode, 'protocol_selection_mode' => 'customer_selects',
            'tag_match_mode' => 'all', 'base_price_irr' => 1_000_000, 'duration_days' => 30,
            'data_allowance_bytes' => null, 'device_limit' => 2, 'sort_order' => 0,
            'min_purchase_quantity' => 1, 'max_purchase_quantity' => 1, 'discount_eligible' => true,
            'auto_renew_allowed' => false, 'custom_plan_allowed' => false, 'trial_allowed' => false,
            'state' => 'draft', 'visibility' => 'hidden', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('plan_offering_tiers')->insert([
            'plan_offering_id' => $offeringId, 'tier_code' => 'normal', 'created_at' => $now,
        ]);
        DB::table('plan_offering_tags')->insert([
            'plan_offering_id' => $offeringId, 'customer_tag_id' => $tagId, 'created_at' => $now,
        ]);
        DB::table('plan_offering_protocol_profiles')->insert([
            'plan_offering_id' => $offeringId, 'panel_protocol_profile_id' => $profileId,
            'customer_selectable' => true, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('plan_offering_required_capabilities')->insert([
            'plan_offering_id' => $offeringId, 'capability_code' => 'create_service', 'created_at' => $now,
        ]);

        $primaryCapacityId = $this->capacity($primaryTargetId, 1, $now);
        $fallbackCapacityId = $this->capacity($fallbackTargetId, 2, $now);

        return [
            'owner_id' => $ownerId, 'administrator_id' => $administratorId, 'user_id' => $userId,
            'tag_id' => $tagId, 'product_id' => $productId, 'primary_server_id' => $primaryServerId,
            'fallback_server_id' => $fallbackServerId, 'primary_target_id' => $primaryTargetId,
            'fallback_target_id' => $fallbackTargetId, 'profile_id' => $profileId,
            'offering_id' => $offeringId, 'primary_capacity_id' => $primaryCapacityId,
            'fallback_capacity_id' => $fallbackCapacityId,
        ];
    }

    /** @param array<string, int> $scenario */
    private function systemPolicyDefinition(array $scenario): PlanOfferingRoutePolicyDefinition
    {
        return new PlanOfferingRoutePolicyDefinition([
            new PlanOfferingRouteDefinition(
                $scenario['primary_server_id'], $scenario['primary_target_id'],
                PlanOfferingRouteType::Primary, 0, false, null, null,
            ),
            new PlanOfferingRouteDefinition(
                $scenario['fallback_server_id'], $scenario['fallback_target_id'],
                PlanOfferingRouteType::Fallback, 1, false,
                "\u{062F}\u{0631} \u{0635}\u{0648}\u{0631}\u{062A} \u{0646}\u{0627}\u{0633}\u{0627}\u{0632}\u{06AF}\u{0627}\u{0631}\u{06CC} \u{0645}\u{0633}\u{06CC}\u{0631} \u{062C}\u{0627}\u{06CC}\u{06AF}\u{0632}\u{06CC}\u{0646} \u{0627}\u{0633}\u{062A}\u{0641}\u{0627}\u{062F}\u{0647} \u{0645}\u{06CC}\u{200C}\u{0634}\u{0648}\u{062F}.",
                'Fallback route may be used.',
            ),
        ]);
    }

    /** @param array<string, int> $scenario */
    private function policyDefinition(array $scenario): PlanOfferingRoutePolicyDefinition
    {
        return new PlanOfferingRoutePolicyDefinition([
            new PlanOfferingRouteDefinition(
                $scenario['primary_server_id'], $scenario['primary_target_id'],
                PlanOfferingRouteType::Primary, 0, true, null, null,
            ),
            new PlanOfferingRouteDefinition(
                $scenario['fallback_server_id'], $scenario['fallback_target_id'],
                PlanOfferingRouteType::Fallback, 1, true,
                "\u{062F}\u{0631} \u{0635}\u{0648}\u{0631}\u{062A} \u{0646}\u{0627}\u{0633}\u{0627}\u{0632}\u{06AF}\u{0627}\u{0631}\u{06CC} \u{0627}\u{0632} \u{0645}\u{0633}\u{06CC}\u{0631} \u{062C}\u{0627}\u{06CC}\u{06AF}\u{0632}\u{06CC}\u{0646} \u{0627}\u{0633}\u{062A}\u{0641}\u{0627}\u{062F}\u{0647} \u{0645}\u{06CC}\u{200C}\u{0634}\u{0648}\u{062F}.",
                'Fallback route may be used when the selected route is unavailable.',
            ),
        ]);
    }

    private function server(string $prefix, mixed $now): int
    {
        return (int) DB::table('sales_servers')->insertGetId([
            'code' => $prefix.'-server-'.Str::lower(Str::random(5)), 'name_fa' => 'Server', 'name_en' => null,
            'description_fa' => null, 'description_en' => null, 'state' => 'disabled', 'visibility' => 'hidden',
            'sort_order' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function target(string $prefix, mixed $now): int
    {
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => $prefix.'-connection-'.Str::lower(Str::random(5)), 'provider_type' => 'fake',
            'name_fa' => 'Panel', 'name_en' => null, 'base_url' => 'https://panel.example.com',
            'encrypted_credentials' => 'ciphertext', 'credential_key_version' => 1, 'tls_policy' => 'system_ca',
            'custom_ca_disk' => null, 'custom_ca_path' => null, 'certificate_pin_sha256' => null,
            'network_policy' => 'public_only', 'state' => 'disabled', 'last_test_status' => null,
            'last_panel_version' => null, 'last_capabilities_hash' => null, 'last_tested_at' => null,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId, 'code' => $prefix.'-target-'.Str::lower(Str::random(5)),
            'kind' => 'inbound', 'name_fa' => 'Target', 'name_en' => null,
            'encrypted_configuration' => 'ciphertext', 'configuration_hash' => hash('sha256', $prefix),
            'configuration_key_version' => 1, 'state' => 'disabled', 'capability_status' => 'declared',
            'capability_evidence_hash' => null, 'capability_verified_at' => null,
            'verified_connection_version' => null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function capacity(int $targetId, int $limit, mixed $now): int
    {
        return (int) DB::table('panel_target_capacities')->insertGetId([
            'panel_service_target_id' => $targetId, 'hard_limit' => $limit, 'held_units' => 0,
            'committed_units' => 0, 'state' => 'enabled', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function fillPrimaryCapacity(int $capacityId): void
    {
        $key = 'route-prefill-capacity-command-001';
        $now = now('UTC');
        DB::table('panel_capacity_reservations')->insert([
            'panel_target_capacity_id' => $capacityId, 'reservation_key' => $key, 'purpose_code' => 'test',
            'units' => 1, 'state' => 'held', 'expires_at' => $now->copy()->addMinutes(20), 'version' => 1,
            'last_command_key' => $key, 'last_payload_hmac' => hash('sha256', $key),
            'last_correlation_id' => 'route-prefill-correlation-001', 'last_source_code' => 'test',
            'last_reason_code' => 'prefill', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function administrator(bool $owner): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(), 'account_type' => 'customer', 'account_status' => 'active',
                'locale' => 'fa', 'first_seen_at' => $now, 'last_seen_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]),
            'status' => 'active', 'is_owner' => $owner, 'permission_version' => 1,
            'last_authenticated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function catalogContext(int $administratorId, string $fingerprint): CatalogChangeContext
    {
        return new CatalogChangeContext(
            $fingerprint,
            'route-policy-correlation-001',
            'route_policy_change',
            'Route policy test change.',
            $administratorId,
        );
    }
}
