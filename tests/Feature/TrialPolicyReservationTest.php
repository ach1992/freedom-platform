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
            'code' => 'trial-cat-'.Str::lower(Str::random(8)),
            'name_translation_key' => 'catalog.category.trial',
            'description_translation_key' => null,
            'sort_order' => 1,
            'state' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'product_category_id' => $categoryId,
            'sku' => 'TRL-'.Str::upper(Str::random(8)),
            'name_translation_key' => 'catalog.product.trial',
            'description_translation_key' => null,
            'visibility' => 'public',
            'sort_order' => 1,
            'state' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'trial-profile-'.Str::lower(Str::random(8)),
            'name_fa' => 'Profile',
            'name_en' => null,
            'protocol' => 'vmess',
            'transport' => 'ws',
            'flow' => null,
            'alpn_code' => null,
            'configuration_hash' => hash('sha256', 'trial-profile')"�