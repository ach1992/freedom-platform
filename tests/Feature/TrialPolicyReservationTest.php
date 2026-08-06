<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\RouteOperationalVerifier;
use App\Modules\Catalog\Application\TrialContext;
use App\Modules\Catalog\Application\TrialMembershipVerifier;
use App\Modules\Catalog\Application\TrialPolicyService;
use App\Modules\Catalog\Application\TrialReservationRequest;
use App\Modules\Catalog\Application\TrialReservationService;
use App\Modules\Catalog\Application\TrialRouteSelector;
use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use App\Modules\Catalog\Domain\TrialPolicyDefinition;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement CAT-006 CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
final class TrialPolicyReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->app->instance(RouteOperationalVerifier::class, new PassingTrialRouteOperationalVerifier);
    }

    public function test_reservation_commit_admin_regrant_and_replay_are_idempotent(): void
    {
        $scenario = $this->scenario(dailyCapacity: 2);
        $definition = $this->policyDefinition($scenario['tag_id'], administratorRegrantAllowed: true);
        $policyContext = $this->catalogContext($scenario['owner_id'], 'trial-policy-create-request-0001');
        $policy = $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $definition,
            $policyContext,
        );
        self::assertTrue($this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $definition,
            $policyContext,
        )->replayed);
        self::assertSame(1, DB::table('trial_policies')->count());
        self::assertSame(1, DB::table('trial_policy_histories')->count());

        $service = $this->serviceWithMembershipAllowed();
        $request = $this->request($scenario['offering_id'], $scenario['user_id']);
        $reserveContext = $this->trialContext('trial-reservation-command-000001', 'trial-correlation-0001');
        $reservation = $service->reserve($request, $reserveContext);
        $reserveReplay = $service->reserve($request, $reserveContext);
        self::assertSame('reserved', $reservation->state);
        self::assertTrue($reserveReplay->replayed);
        self::assertSame($reservation->reservationId, $reserveReplay->reservationId);
        self::assertSame(1, DB::table('trial_reservations')->count());

        $commitContext = $this->trialContext('trial-commit-command-000000001', 'trial-correlation-0002');
        $committed = $service->commit($reservation->reservationId, 1, $commitContext);
        $commitReplay = $service->commit($reservation->reservationId, 1, $commitContext);
        self::assertSame('committed', $committed->state);
        self::assertSame(2, $committed->version);
        self::assertTrue($commitReplay->replayed);
        self::assertSame(1, (int) DB::table('trial_daily_capacity_counters')->value('committed_count'));

        try {
            $service->reserve(
                $this->request($scenario['offering_id'], $scenario['user_id']),
                $this->trialContext('trial-second-before-reset-0001', 'trial-correlation-0003'),
            );
            self::fail('Expected one-per-user rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Trial one-per-user policy is already consumed.', $exception->getMessage());
        }

        $resetContext = $this->catalogContext($scenario['owner_id'], 'trial-reset-request-000000001');
        $reset = $service->resetEligibility($reservation->reservationId, 1, $resetContext);
        $resetReplay = $service->resetEligibility($reservation->reservationId, 1, $resetContext);
        self::assertSame(3, $reset->version);
        self::assertTrue($resetReplay->replayed);
        self::assertNull(DB::table('trial_reservations')->where('id', $reservation->reservationId)->value('active_user_id'));

        $second = $service->reserve(
            $this->request($scenario['offering_id'], $scenario['user_id']),
            $this->trialContext('trial-second-after-reset-00001', 'trial-correlation-0004'),
        );
        self::assertSame('reserved', $second->state);
        self::assertSame(0, $second->dailyCapacityAvailable);
        self::assertSame($policy->targetId, $second->policyId);

        try {
            DB::table('trial_reservations')->where('id', $second->reservationId)->update(['data_bytes' => 1]);
            self::fail('Expected immutable trial reservation rejection.');
        } catch (QueryException) {
            self::assertSame(1_073_741_824, (int) DB::table('trial_reservations')
                ->where('id', $second->reservationId)
                ->value('data_bytes'));
        }
    }

    public function test_fallback_policy_controls_route_substitution_and_disclosure_snapshot(): void
    {
        $scenario = $this->scenario(dailyCapacity: 3);
        $this->fillPrimaryCapacity($scenario['primary_capacity_id']);
        $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition($scenario['tag_id'], fallbackAllowed: true),
            $this->catalogContext($scenario['owner_id'], 'trial-policy-fallback-create-01'),
        );

        $reserved = $this->serviceWithMembershipAllowed()->reserve(
            $this->request($scenario['offering_id'], $scenario['user_id']),
            $this->trialContext('trial-fallback-reserve-command-01', 'trial-correlation-0005'),
        );
        self::assertTrue($reserved->fallbackUsed);
        self::assertSame($scenario['fallback_target_id'], $reserved->serviceTargetId);
        self::assertSame('سرور جایگزین آزمایشی انتخاب شد.', $reserved->disclosureFa);

        $disabledScenario = $this->scenario(dailyCapacity: 3);
        $this->fillPrimaryCapacity($disabledScenario['primary_capacity_id']);
        $this->app->make(TrialPolicyService::class)->create(
            $disabledScenario['offering_id'],
            $this->policyDefinition($disabledScenario['tag_id'], fallbackAllowed: false),
            $this->catalogContext($disabledScenario['owner_id'], 'trial-policy-no-fallback-create'),
        );

        try {
            $this->serviceWithMembershipAllowed()->reserve(
                $this->request($disabledScenario['offering_id'], $disabledScenario['user_id']),
                $this->trialContext('trial-no-fallback-reserve-0001', 'trial-correlation-0006'),
            );
            self::fail('Expected unavailable primary route without fallback.');
        } catch (RouteCandidateUnavailable $exception) {
            self::assertSame('No configured trial route has available capacity.', $exception->getMessage());
        }
        self::assertFalse(DB::table('trial_reservations')->where('user_id', $disabledScenario['user_id'])->exists());
    }

    public function test_identity_membership_capacity_replay_and_database_guards_are_enforced(): void
    {
        $scenario = $this->scenario(dailyCapacity: 1, phoneEvidence: 'telegram_only');
        $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition(
                $scenario['tag_id'],
                membershipRequired: true,
                phonePolicy: PhoneVerificationPolicy::Both,
                onePerPhone: true,
            ),
            $this->catalogContext($scenario['owner_id'], 'trial-identity-policy-create'),
        );

        try {
            $this->app->make(TrialReservationService::class)->reserve(
                $this->request($scenario['offering_id'], $scenario['user_id']),
                $this->trialContext('trial-identity-reserve-001', 'trial-correlation-0077'),
            );
            self::fail('Expected both phone evidences to be required.');
        } catch (DomainException $exception) {
            self::assertSame('Trial phone-verification requirement is not satisfied.', $exception->getMessage());
        }

        $context = $this->trialContext('trial-membership-command-001', 'trial-correlation-0008');
        try {
            $this->serviceWithMembershipAllowed()->reserve(
                $this->request($scenario['offering_id'], $scenario['user_id']),
                $context,
            );
            self::fail('Expected both phone evidences to be required.');
        } catch (DomainException $exception) {
            self::assertSame('Trial phone-verification requirement is not satisfied.', $exception->getMessage());
        }

        DB::table('phone_verification_evidences')->insert([
            'phone_number_id' => DB::table('phone_numbers')->where('active_user_id', $scenario['user_id'])->value('id'),
            'method' => 'sms_otp',
            'policy_version' => 1,
            'verified_at' => now('UTC'),
            'invalidated_at' => null,
            'telegram_account_id' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $service = $this->serviceWithMembershipAllowed();
        $reserved = $service->reserve(
            $this->request($scenario['offering_id'], $scenario['user_id']),
            $this->trialContext('trial-identity-reserve-002', 'trial-correlation-0009'),
        );
        self::assertSame('reserved', $reserved->state);

        try {
            $service->reserve(
                $this->request($scenario['offering_id'], $scenario['user_id']),
                $this->trialContext('trial-capacity-exceeded-001', 'trial-correlation-0010'),
            );
            self::fail('Expected daily capacity rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Trial daily capacity is exhausted.', $exception->getMessage());
        }

        try {
            $service->reserve(
                $this->request($scenario['offering_id'], $scenario['user_id'], $scenario['primary_route_id']),
                $context,
            );
            self::fail('Expected trial reservation command conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Trial reservation command key conflict.', $exception->getMessage());
        }

        try {
            DB::table('trial_daily_capacity_counters')->update(['reserved_count' => 99]);
            self::fail('Expected daily capacity counter guard to reject tampering.');
        } catch (QueryException) {
            self::assertSame(1, (int) DB::table('trial_daily_capacity_counters')->value('reserved_count'));
        }
    }

    public function test_unauthorized_policy_mutation_is_rejected_without_partial_effect(): void
    {
        $scenario = $this->scenario(dailyCapacity: 1);
        DB::table('administrator_roles')->where('administrator_id', $scenario['administrator_id'])->delete();

        $this->expectException(AuthorizationException::class);
        $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition($scenario['tag_id']),
            $this->catalogContext($scenario['administrator_id'], 'trial-policy-unauthorized-0001'),
        );
    }

    /** @return array<string, int> */
    private function scenario(int $dailyCapacity, string $phoneEvidence = 'none'): array
    {
        $now = now('UTC');
        $existingOwnerId = DB::table('administrators')->where('is_owner', true)->value('id');
        $ownerId = $existingOwnerId === null ? $this->administrator(true) : (int) $existingOwnerId;
        $administratorId = $this->administrator(false);
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'trial-tag-'.Str::lower(Str::random(8)),
            'name_translation_key' => 'customer_tags.trial',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = $this->customer($ownerId, $tagId, $phoneEvidence);
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'trial-category-'.Str::lower(Str::random(8)),
            'name_fa' => 'Trial Category',
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
            'code' => 'trial-product-'.Str::lower(Str::random(8)),
            'name_fa' => 'Trial Product',
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
        $primaryServerId = $this->server('trial-primary', $now);
        $fallbackServerId = $this->server('trial-fallback', $now);
        $primaryTargetId = $this->target('trial-primary', $now);
        $fallbackTargetId = $this->target('trial-fallback', $now);
        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'trial-profile-'.Str::lower(Str::random(8)),
            'name_fa' => 'Trial Profile',
            'name_en' => null,
            'protocol_family' => 'vless',
            'transport' => 'ws',
            'security_layer' => 'tls',
            'host' => null,
            'sni' => null,
            'path' => '/trial',
            'port' => 443,
            'flow' => null,
            'state' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([$primaryTargetId, $fallbackTargetId] as $targetId) {
            DB::table('panel_target_protocol_profiles')->insert([
                'panel_service_target_id' => $targetId,
                'panel_protocol_profile_id' => $profileId,
                'customer_selectable' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('panel_target_capabilities')->insert([
                'panel_service_target_id' => $targetId,
                'capability_code' => 'create_service',
                'verification_status' => 'declared',
                'evidence_hash' => null,
                'verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $offeringId = (int) DB::table('plan_offerings')->insertGetId([
            'code' => 'trial-offering-'.Str::lower(Str::random(8)),
            'product_id' => $productId,
            'variant_id' => null,
            'sales_server_id' => $primaryServerId,
            'panel_service_target_id' => $primaryTargetId,
            'service_mode_code' => 'shared',
            'service_mode_label_fa' => 'اشتراکی',
            'service_mode_label_en' => 'Shared',
            'audience' => 'customers',
            'server_selection_mode' => 'system_selects',
            'protocol_selection_mode' => 'fixed',
            'tag_match_mode' => 'all',
            'base_price_irr' => 0,
            'duration_days' => 1,
            'data_allowance_bytes' => 1_073_741_824,
            'device_limit' => 1,
            'sort_order' => 0,
            'min_purchase_quantity' => 1,
            'max_purchase_quantity' => 1,
            'discount_eligible' => false,
            'auto_renew_allowed' => false,
            'custom_plan_allowed' => false,
            'trial_allowed' => true,
            'state' => 'draft',
            'visibility' => 'hidden',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_tiers')->insert([
            'plan_offering_id' => $offeringId,
            'tier_code' => 'normal',
            'created_at' => $now,
        ]);
        DB::table('plan_offering_tags')->insert([
            'plan_offering_id' => $offeringId,
            'customer_tag_id' => $tagId,
            'created_at' => $now,
        ]);
        DB::table('plan_offering_protocol_profiles')->insert([
            'plan_offering_id' => $offeringId,
            'panel_protocol_profile_id' => $profileId,
            'customer_selectable' => false,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_required_capabilities')->insert([
            'plan_offering_id' => $offeringId,
            'capability_code' => 'create_service',
            'created_at' => $now,
        ]);
        $routePolicyId = (int) DB::table('plan_offering_route_policies')->insertGetId([
            'plan_offering_id' => $offeringId,
            'configuration_hash' => hash('sha256', 'trial-routes-'.$offeringId),
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $primaryRouteId = (int) DB::table('plan_offering_routes')->insertGetId([
            'plan_offering_route_policy_id' => $routePolicyId,
            'sales_server_id' => $primaryServerId,
            'panel_service_target_id' => $primaryTargetId,
            'route_type' => 'primary',
            'priority' => 0,
            'customer_selectable' => false,
            'disclosure_fa' => null,
            'disclosure_en' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fallbackRouteId = (int) DB::table('plan_offering_routes')->insertGetId([
            'plan_offering_route_policy_id' => $routePolicyId,
            'sales_server_id' => $fallbackServerId,
            'panel_service_target_id' => $fallbackTargetId,
            'route_type' => 'fallback',
            'priority' => 1,
            'customer_selectable' => false,
            'disclosure_fa' => 'سرور جایگزین آزمایشی انتخاب شد.',
            'disclosure_en' => 'A fallback trial server was selected.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $primaryCapacityId = $this->capacity($primaryTargetId, 1, $now);
        $fallbackCapacityId = $this->capacity($fallbackTargetId, 5, $now);

        return [
            'owner_id' => $ownerId,
            'administrator_id' => $administratorId,
            'user_id' => $userId,
            'tag_id' => $tagId,
            'offering_id' => $offeringId,
            'profile_id' => $profileId,
            'primary_route_id' => $primaryRouteId,
            'fallback_route_id' => $fallbackRouteId,
            'primary_target_id' => $primaryTargetId,
            'fallback_target_id' => $fallbackTargetId,
            'primary_capacity_id' => $primaryCapacityId,
            'fallback_capacity_id' => $fallbackCapacityId,
            'daily_capacity' => $dailyCapacity,
        ];
    }

    private function policyDefinition(
        int $tagId,
        bool $administratorRegrantAllowed = false,
        bool $fallbackAllowed = false,
        PhoneVerificationPolicy $phonePolicy = PhoneVerificationPolicy::None,
        bool $membershipRequired = false,
        bool $onePerPhone = false,
    ): TrialPolicyDefinition {
        return new TrialPolicyDefinition(
            true,
            1_073_741_824,
            1,
            2,
            $phonePolicy,
            $membershipRequired,
            true,
            $onePerPhone,
            $administratorRegrantAllowed,
            $fallbackAllowed,
            PlanOfferingTagMatchMode::All,
            'service.trial.delivery',
            ['normal'],
            [$tagId],
        );
    }

    private function request(int $offeringId, int $userId, ?int $requestedRouteId = null): TrialReservationRequest
    {
        return new TrialReservationRequest(
            $offeringId,
            $userId,
            $requestedRouteId,
            null,
            (new DateTimeImmutable('now'))->modify('+10 minutes'),
        );
    }

    private function serviceWithMembershipAllowed(): TrialReservationService
    {
        $this->app->instance(TrialMembershipVerifier::class, new PassingTrialMembershipVerifier);
        $this->app->forgetInstance(TrialRouteSelector::class);
        $this->app->forgetInstance(TrialReservationService::class);

        return $this->app->make(TrialReservationService::class);
    }

    private function customer(int $ownerId, int $tagId, string $phoneEvidence = 'none'): int
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tierId = (int) DB::table('customer_tiers')->where('code', 'normal')->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => $phoneEvidence === 'none' ? 'unverified' : 'verified',
            'identity_verification_status' => 'unverified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => $tagId,
            'assigned_by_administrator_id' => $ownerId,
            'assigned_at' => $now,
            'removed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($phoneEvidence !== 'none') {
            $hash = hash('sha256', 'trial-phone-'.$userId);
            $phoneId = (int) DB::table('phone_numbers')->insertGetId([
                'user_id' => $userId,
                'active_user_id' => $userId,
                'encrypted_value' => 'ciphertext',
                'lookup_hash' => $hash,
                'active_lookup_hash' => $hash,
                'hash_key_version' => 1,
                'status' => 'verified',
                'verification_policy' => $phoneEvidence === 'both' ? 'both' : 'telegram_contact_only',
                'verification_policy_version' => 1,
                'last_verification_method' => $phoneEvidence === 'both' ? 'sms_otp' : 'telegram_contact',
                'verified_via_telegram_account_id' => null,
                'verified_at' => $now,
                'released_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $methods = $phoneEvidence === 'both' ? ['telegram_contact', 'sms_otp'] : ['telegram_contact'];
            foreach ($methods as $method) {
                DB::table('phone_verification_evidences')->insert([
                    'phone_number_id' => $phoneId,
                    'method' => $method,
                    'policy_version' => 1,
                    'verified_at' => $now,
                    'invalidated_at' => null,
                    'telegram_account_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return $userId;
    }

    private function server(string $prefix, mixed $now): int
    {
        return (int) DB::table('sales_servers')->insertGetId([
            'code' => $prefix.'-server-'.Str::lower(Str::random(8)),
            'name_fa' => 'Server',
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
    }

    private function target(string $prefix, mixed $now): int
    {
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => $prefix.'-connection-'.Str::lower(Str::random(8)),
            'provider_type' => 'fake',
            'name_fa' => 'Panel',
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

        return (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId,
            'code' => $prefix.'-target-'.Str::lower(Str::random(8)),
            'kind' => 'inbound',
            'name_fa' => 'Target',
            'name_en' => null,
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', $prefix),
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
    }

    private function capacity(int $targetId, int $limit, mixed $now): int
    {
        return (int) DB::table('panel_target_capacities')->insertGetId([
            'panel_service_target_id' => $targetId,
            'hard_limit' => $limit,
            'held_units' => 0,
            'committed_units' => 0,
            'state' => 'enabled',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function fillPrimaryCapacity(int $capacityId): void
    {
        $key = 'trial-prefill-capacity-command-001';
        $now = now('UTC');
        DB::table('panel_capacity_reservations')->insert([
            'panel_target_capacity_id' => $capacityId,
            'reservation_key' => $key,
            'purpose_code' => 'test',
            'units' => 1,
            'state' => 'held',
            'expires_at' => $now->copy()->addMinutes(20),
            'version' => 1,
            'last_command_key' => $key,
            'last_payload_hmac' => hash('sha256', $key),
            'last_correlation_id' => 'trial-prefill-correlation-001',
            'last_source_code' => 'test',
            'last_reason_code' => 'prefill',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function administrator(bool $owner): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function catalogContext(int $administratorId, string $fingerprint): CatalogChangeContext
    {
        return new CatalogChangeContext(
            $fingerprint,
            'trial-policy-correlation-001',
            'trial_policy_change',
            'Trial policy test change.',
            $administratorId,
        );
    }

    private function trialContext(string $commandKey, string $correlationId): TrialContext
    {
        return new TrialContext($commandKey, $correlationId, 'test', 'trial_test');
    }
}

final class PassingTrialRouteOperationalVerifier implements RouteOperationalVerifier
{
    public function assertOperational(
        Connection $connection,
        int $offeringId,
        int $salesServerId,
        int $serviceTargetId,
        int $protocolProfileId,
    ): void {}
}

final class PassingTrialMembershipVerifier implements TrialMembershipVerifier
{
    public function assertSatisfied(Connection $connection, int $userId, int $offeringId, int $policyId): void {}
}
