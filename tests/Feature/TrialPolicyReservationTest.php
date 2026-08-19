<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Application\RouteOperationalVerifier;
use App\Modules\Catalog\Application\TrialContext;
use App\Modules\Catalog\Application\TrialMembershipVerifier;
use App\Modules\Catalog\Application\TrialPolicyService;
use App\Modules\Catalog\Application\TrialReservationRequest;
use App\Modules\Catalog\Application\TrialReservationService;
use App\Modules\Catalog\Application\TrialRouteSelector;
use App\Modules\Catalog\Domain\OfferingProtocolAssignment;
use App\Modules\Catalog\Domain\PlanOfferingAudience;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\PlanOfferingProtocolSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServiceMode;
use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use App\Modules\Catalog\Domain\TrialPolicyDefinition;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use App\Modules\Orders\Application\NonPaidOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use App\Modules\Orders\Domain\OrderSourceType;
use App\Modules\Orders\Domain\OrderState;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
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
        $reset = $service->resetEligibility($reservation->reservationId, 2, $resetContext);
        $resetReplay = $service->resetEligibility($reservation->reservationId, 2, $resetContext);
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

    public function test_committed_trial_materializes_and_queues_one_zero_cost_order_with_replay(): void
    {
        $scenario = $this->scenario(dailyCapacity: 2);
        $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition($scenario['tag_id']),
            $this->catalogContext($scenario['owner_id'], 'trial-order-policy-create-0001'),
        );

        $trial = $this->serviceWithMembershipAllowed();
        $reservationCommandKey = 'trial-order-reservation-command-000001';
        $reservation = $trial->reserve(
            $this->request($scenario['offering_id'], $scenario['user_id']),
            $this->trialContext($reservationCommandKey, 'trial-order-reserve-correlation-01'),
        );
        $committed = $trial->commit(
            $reservation->reservationId,
            1,
            $this->trialContext('trial-order-commit-command-00000001', 'trial-order-commit-correlation-01'),
        );
        self::assertSame('committed', $committed->state);

        // Trial reservation/commit authority may be established while the offering is still a
        // draft. Order materialization is a separate boundary and intentionally requires the
        // canonical active Offering plus its immutable activation history.
        $this->activateScenarioOffering($scenario);

        $paymentIntentCount = DB::table('payment_intents')->count();
        $settlementCount = DB::table('purchase_settlements')->count();
        $source = $this->app->make(OrderSourceAuthorizationService::class);
        $authorization = $source->authorizeTrial($reservationCommandKey, 'trial-order-source-correlation-01');
        $authorizationReplay = $source->authorizeTrial($reservationCommandKey, 'trial-order-source-correlation-02');
        self::assertFalse($authorization->replayed);
        self::assertTrue($authorizationReplay->replayed);
        self::assertSame($authorization->authorizationId, $authorizationReplay->authorizationId);
        self::assertSame(OrderSourceType::Trial, $authorization->sourceType);

        $authorizationRow = DB::table('order_source_authorizations')->where('id', $authorization->authorizationId)->first();
        self::assertNotNull($authorizationRow);
        self::assertSame($reservation->reservationId, (int) $authorizationRow->trial_reservation_id);
        self::assertSame($reservationCommandKey, $authorizationRow->trial_reservation_command_key);
        self::assertSame('trial', $authorizationRow->source_type);

        $orders = $this->app->make(NonPaidOrderService::class);
        $order = $orders->materialize($authorization->publicId, 'trial-order-materialize-correlation-01');
        $orderReplay = $orders->materialize($authorization->publicId, 'trial-order-materialize-correlation-02');
        self::assertFalse($order->replayed);
        self::assertTrue($orderReplay->replayed);
        self::assertSame($order->orderId, $orderReplay->orderId);
        self::assertSame(OrderSourceType::Trial, $order->sourceType);
        self::assertSame(0, $order->commercialAmount->amount());

        $queue = $this->app->make(InitialProvisioningQueueService::class);
        $queued = $queue->queueInitial($order->orderPublicId, 'trial-order-queue-correlation-01');
        $queueReplay = $queue->queueInitial($order->orderPublicId, 'trial-order-queue-correlation-02');
        self::assertFalse($queued->replayed);
        self::assertTrue($queueReplay->replayed);
        self::assertSame($queued->serviceSubscriptionId, $queueReplay->serviceSubscriptionId);
        self::assertSame($queued->provisioningOperationId, $queueReplay->provisioningOperationId);
        self::assertSame($queued->outboxEventId, $queueReplay->outboxEventId);
        self::assertSame(OrderState::ProvisioningQueued, $queued->orderState);
        self::assertSame(ProvisioningState::Queued, $queued->provisioningState);

        $orderRow = DB::table('orders')->where('id', $order->orderId)->first();
        self::assertNotNull($orderRow);
        self::assertSame('trial', $orderRow->source_type);
        self::assertSame($authorization->authorizationId, (int) $orderRow->order_source_authorization_id);
        self::assertNull($orderRow->purchase_settlement_id);
        self::assertNull($orderRow->payment_intent_id);
        self::assertSame(0, (int) $orderRow->total_amount_irr);
        self::assertSame(1, DB::table('service_subscriptions')->where('order_id', $order->orderId)->count());
        self::assertSame(1, DB::table('provisioning_operations')->where('order_id', $order->orderId)->count());
        self::assertSame(1, DB::table('outbox_messages')->where('id', $queued->outboxEventId)->count());
        self::assertSame($paymentIntentCount, DB::table('payment_intents')->count());
        self::assertSame($settlementCount, DB::table('purchase_settlements')->count());
    }

    public function test_fallback_policy_controls_route_substitution_and_disclosure_snapshot(): void
    {
        $scenario = $this->scenario(dailyCapacity: 3, primaryCapacity: 1);
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

        $disabledScenario = $this->scenario(
            dailyCapacity: 3,
            ownerId: $scenario['owner_id'],
            primaryCapacity: 1,
        );
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
        self::assertFalse(DB::table('trial_daily_capacity_counters')
            ->where('trial_policy_id', (int) DB::table('trial_policies')
                ->where('plan_offering_id', $disabledScenario['offering_id'])
                ->value('id'))
            ->exists());
    }

    public function test_phone_membership_release_and_daily_capacity_guards_fail_closed(): void
    {
        $scenario = $this->scenario(dailyCapacity: 1, phoneEvidence: 'both');
        $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition(
                $scenario['tag_id'],
                phonePolicy: PhoneVerificationPolicy::Both,
                membershipRequired: true,
                onePerPhone: true,
            ),
            $this->catalogContext($scenario['owner_id'], 'trial-policy-membership-create'),
        );

        try {
            $this->app->make(TrialReservationService::class)->reserve(
                $this->request($scenario['offering_id'], $scenario['user_id']),
                $this->trialContext('trial-membership-denied-00001', 'trial-correlation-0007'),
            );
            self::fail('Expected membership fail-closed behavior.');
        } catch (DomainException $exception) {
            self::assertSame('Trial membership verification is unavailable.', $exception->getMessage());
        }

        $service = $this->serviceWithMembershipAllowed();
        $reserved = $service->reserve(
            $this->request($scenario['offering_id'], $scenario['user_id']),
            $this->trialContext('trial-membership-allowed-0001', 'trial-correlation-0008'),
        );
        $released = $service->release(
            $reserved->reservationId,
            1,
            $this->trialContext('trial-release-command-00000001', 'trial-correlation-0009'),
        );
        self::assertSame('released', $released->state);
        self::assertNull(DB::table('trial_reservations')->where('id', $reserved->reservationId)->value('active_phone_number_id'));

        $secondUserId = $this->customer($scenario['owner_id'], $scenario['tag_id'], phoneEvidence: 'both');
        $second = $service->reserve(
            $this->request($scenario['offering_id'], $secondUserId),
            $this->trialContext('trial-after-release-command-001', 'trial-correlation-0010'),
        );
        self::assertSame('reserved', $second->state);

        $thirdUserId = $this->customer($scenario['owner_id'], $scenario['tag_id'], phoneEvidence: 'both');
        try {
            $service->reserve(
                $this->request($scenario['offering_id'], $thirdUserId),
                $this->trialContext('trial-daily-capacity-command-01', 'trial-correlation-0011'),
            );
            self::fail('Expected daily capacity exhaustion.');
        } catch (DomainException $exception) {
            self::assertSame('Trial daily capacity is exhausted.', $exception->getMessage());
        }
    }

    public function test_phone_policy_and_administrator_authorization_are_enforced(): void
    {
        $scenario = $this->scenario(dailyCapacity: 2, phoneEvidence: 'telegram');
        $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition(
                $scenario['tag_id'],
                phonePolicy: PhoneVerificationPolicy::Both,
                onePerPhone: true,
            ),
            $this->catalogContext($scenario['owner_id'], 'trial-policy-phone-create-0001'),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Trial phone-verification requirement is not satisfied.');
        $this->serviceWithMembershipAllowed()->reserve(
            $this->request($scenario['offering_id'], $scenario['user_id']),
            $this->trialContext('trial-phone-policy-denied-0001', 'trial-correlation-0012'),
        );
    }

    public function test_unauthorized_administrator_cannot_create_trial_policy(): void
    {
        $scenario = $this->scenario(dailyCapacity: 2);
        $this->expectException(AuthorizationException::class);
        $this->app->make(TrialPolicyService::class)->create(
            $scenario['offering_id'],
            $this->policyDefinition($scenario['tag_id']),
            $this->catalogContext($scenario['administrator_id'], 'trial-policy-unauthorized-0001'),
        );
    }

    /** @return array<string, int> */
    private function scenario(
        int $dailyCapacity,
        string $phoneEvidence = 'none',
        ?int $ownerId = null,
        ?int $primaryCapacity = null,
    ): array {
        $this->scenarioDailyCapacity = $dailyCapacity;
        $now = now('UTC');
        $ownerId ??= $this->administrator(true);
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

        $offeringCode = 'trial-offering-'.Str::lower(Str::random(8));
        $createdOffering = $this->app->make(PlanOfferingService::class)->create(
            new PlanOfferingDefinition(
                $offeringCode,
                $productId,
                null,
                $primaryServerId,
                $primaryTargetId,
                new PlanOfferingServiceMode('shared', 'اشتراکی', 'Shared'),
                PlanOfferingAudience::Customers,
                PlanOfferingServerSelectionMode::System,
                PlanOfferingProtocolSelectionMode::Fixed,
                PlanOfferingTagMatchMode::All,
                0,
                1,
                1_073_741_824,
                1,
                0,
                1,
                1,
                false,
                false,
                false,
                true,
                ['normal'],
                [$tagId],
                [new OfferingProtocolAssignment($profileId, false, true)],
                ['create_service'],
                [],
                [],
            ),
            $this->catalogContext($ownerId, 'create-'.$offeringCode),
        );
        $offeringId = $createdOffering->targetId;
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
        $primaryCapacityId = $this->capacity($primaryTargetId, $primaryCapacity ?? $dailyCapacity, $now);
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
            $this->currentScenarioDailyCapacity(),
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

    /** @param array<string,int> $scenario */
    private function activateScenarioOffering(array $scenario): void
    {
        /** @var object{sales_server_id:int|string,panel_service_target_id:int|string,version:int|string}|null $offering */
        $offering = DB::table('plan_offerings')->where('id', $scenario['offering_id'])->first([
            'sales_server_id', 'panel_service_target_id', 'version',
        ]);
        self::assertNotNull($offering);

        /** @var object{panel_connection_id:int|string}|null $target */
        $target = DB::table('panel_service_targets')->where('id', (int) $offering->panel_service_target_id)->first(['panel_connection_id']);
        self::assertNotNull($target);

        $now = now('UTC');
        $connectionId = (int) $target->panel_connection_id;
        $targetId = (int) $offering->panel_service_target_id;
        $evidenceHash = hash('sha256', 'trial-order-target-evidence:'.$scenario['offering_id']);
        DB::table('panel_connections')->where('id', $connectionId)->update([
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => 'trial-order-test-1.0.0',
            'last_capabilities_hash' => hash('sha256', 'trial-order-capabilities:'.$scenario['offering_id']),
            'last_tested_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_target_capabilities')->where('panel_service_target_id', $targetId)->update([
            'verification_status' => 'verified',
            'evidence_hash' => $evidenceHash,
            'verified_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => $evidenceHash,
            'capability_verified_at' => $now,
            'verified_connection_version' => 1,
            'updated_at' => $now,
        ]);
        DB::table('sales_servers')->where('id', (int) $offering->sales_server_id)->update([
            'state' => 'active',
            'visibility' => 'listed',
            'updated_at' => $now,
        ]);

        $activated = $this->app->make(PlanOfferingService::class)->activate(
            $scenario['offering_id'],
            (int) $offering->version,
            $this->catalogContext($scenario['owner_id'], 'trial-order-offering-activate-0001'),
        );
        self::assertTrue($activated->changed);
    }

    private int $scenarioDailyCapacity = 2;

    private function currentScenarioDailyCapacity(): int
    {
        return $this->scenarioDailyCapacity;
    }

    private function request(int $offeringId, int $userId): TrialReservationRequest
    {
        return new TrialReservationRequest(
            $offeringId,
            $userId,
            null,
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
        $key = 'trial-prefill-capacity:'.$capacityId;
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
