<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorAccessService;
use App\Modules\AccessControl\Domain\PermissionEffect;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use App\Modules\Orders\Domain\OrderSourceType;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionContext;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 PRO-002 ADM-002 ACL-001 ACL-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class OrderSourceAuthorizationServiceTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_benefit_free_service_entitlement_authorizes_exact_snapshot_once(): void
    {
        $offering = $this->benefitOffering('order-source-benefit');
        $userId = $this->benefitUser();
        $entitlementPublicId = $this->freeServiceEntitlement($userId, $offering, 'order-source-benefit');

        $service = $this->app->make(OrderSourceAuthorizationService::class);
        $created = $service->authorizeBenefitCode($entitlementPublicId, 'source-benefit-correlation-01');
        $replay = $service->authorizeBenefitCode($entitlementPublicId, 'source-benefit-correlation-02');

        self::assertFalse($created->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($created->authorizationId, $replay->authorizationId);
        self::assertSame($created->publicId, $replay->publicId);
        self::assertSame(OrderSourceType::BenefitCode, $created->sourceType);
        self::assertSame($userId, $created->userId);
        self::assertSame($offering['id'], $created->planOfferingId);
        self::assertSame('source-benefit-correlation-01', $replay->correlationId);

        $authorization = DB::table('order_source_authorizations')->where('id', $created->authorizationId)->first();
        $entitlement = DB::table('benefit_code_free_service_entitlements')->where('public_id', $entitlementPublicId)->first();
        self::assertNotNull($authorization);
        self::assertNotNull($entitlement);
        self::assertSame($entitlement->configuration_snapshot, $authorization->configuration_snapshot);
        self::assertSame($entitlement->configuration_hash, $authorization->configuration_snapshot_hash);
        self::assertSame(1, DB::table('order_source_authorizations')->where('source_type', 'benefit_code')->count());

        $this->assertQueryRejected(fn () => DB::table('order_source_authorizations')
            ->where('id', $created->authorizationId)
            ->update(['reason_code' => 'mutated']));
        $this->assertQueryRejected(fn () => DB::table('order_source_authorizations')
            ->where('id', $created->authorizationId)
            ->delete());
    }

    public function test_database_rejects_forged_benefit_snapshot_even_with_self_consistent_hash(): void
    {
        $offering = $this->benefitOffering('order-source-benefit-forge');
        $userId = $this->benefitUser();
        $entitlementPublicId = $this->freeServiceEntitlement($userId, $offering, 'order-source-benefit-forge');
        $entitlement = DB::table('benefit_code_free_service_entitlements')->where('public_id', $entitlementPublicId)->first();
        self::assertNotNull($entitlement);

        $forgedSnapshot = '{"forged":true}';
        $this->assertQueryRejected(fn () => DB::table('order_source_authorizations')->insert([
            'public_id' => (string) Str::ulid(),
            'source_type' => 'benefit_code',
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
            'trial_reservation_id' => null,
            'trial_reservation_command_key' => null,
            'benefit_entitlement_id' => (int) $entitlement->id,
            'benefit_entitlement_public_id' => $entitlementPublicId,
            'authorization_key' => 'forged-benefit-'.substr(hash('sha256', $entitlementPublicId), 0, 40),
            'request_payload_hash' => hash('sha256', 'forged-benefit-request'),
            'configuration_snapshot' => $forgedSnapshot,
            'configuration_snapshot_hash' => hash('sha256', $forgedSnapshot),
            'actor_type' => 'system',
            'actor_id' => null,
            'reason_code' => 'benefit_code_free_service',
            'correlation_id' => 'forged-benefit-correlation-01',
            'created_at' => now('UTC'),
        ]));
        self::assertSame(0, DB::table('order_source_authorizations')->count());
    }

    public function test_administrator_grant_requires_live_permission_and_replays_only_same_request(): void
    {
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $offering = $this->activeBenefitOffering('order-source-admin');
        $service = $this->app->make(OrderSourceAuthorizationService::class);

        $ownerGrant = $service->authorizeAdministratorGrant(
            'admin-grant-owner-00000001',
            $ownerId,
            $userId,
            $offering['id'],
            'manual_service_grant',
            'admin-grant-correlation-01',
        );
        $ownerReplay = $service->authorizeAdministratorGrant(
            'admin-grant-owner-00000001',
            $ownerId,
            $userId,
            $offering['id'],
            'manual_service_grant',
            'admin-grant-correlation-02',
        );
        self::assertFalse($ownerGrant->replayed);
        self::assertTrue($ownerReplay->replayed);
        self::assertSame($ownerGrant->authorizationId, $ownerReplay->authorizationId);
        self::assertSame('admin-grant-correlation-01', $ownerReplay->correlationId);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Order source authorization key conflicts with another request.');
        $service->authorizeAdministratorGrant(
            'admin-grant-owner-00000001',
            $ownerId,
            $userId,
            $offering['id'],
            'different_reason',
            'admin-grant-correlation-03',
        );
    }

    public function test_non_owner_administrator_permission_allow_and_explicit_deny_are_enforced(): void
    {
        $ownerId = $this->benefitOwner();
        $administratorId = $this->nonOwnerAdministrator();
        $userId = $this->benefitUser();
        $offering = $this->activeBenefitOffering('order-source-admin-permission');
        $source = $this->app->make(OrderSourceAuthorizationService::class);

        try {
            $source->authorizeAdministratorGrant(
                'admin-grant-denied-00000001',
                $administratorId,
                $userId,
                $offering['id'],
                'manual_service_grant',
                'admin-denied-correlation-01',
            );
            self::fail('Expected administrator grant permission rejection.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('order_source_authorizations')->count());
        }

        $access = $this->app->make(AdministratorAccessService::class);
        $access->setPermissionOverride(
            $administratorId,
            'services.grant_single',
            PermissionEffect::Allow,
            $this->benefitContext($ownerId, 'order-source-admin-allow'),
        );
        $allowed = $source->authorizeAdministratorGrant(
            'admin-grant-allowed-0000001',
            $administratorId,
            $userId,
            $offering['id'],
            'manual_service_grant',
            'admin-allowed-correlation-01',
        );
        self::assertFalse($allowed->replayed);
        self::assertSame($administratorId, $allowed->actorId);

        $access->setPermissionOverride(
            $administratorId,
            'services.grant_single',
            PermissionEffect::Deny,
            $this->benefitContext($ownerId, 'order-source-admin-deny'),
        );
        $this->expectException(AuthorizationException::class);
        $source->authorizeAdministratorGrant(
            'admin-grant-denied-00000002',
            $administratorId,
            $userId,
            $offering['id'],
            'manual_service_grant',
            'admin-denied-correlation-02',
        );
    }

    public function test_database_rejects_forged_admin_snapshot_and_unsupported_zero_cost_source(): void
    {
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $offering = $this->benefitOffering('order-source-admin-forge');
        $forgedSnapshot = '{"forged":true}';

        $base = [
            'public_id' => (string) Str::ulid(),
            'source_type' => 'admin_grant',
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
            'trial_reservation_id' => null,
            'trial_reservation_command_key' => null,
            'benefit_entitlement_id' => null,
            'benefit_entitlement_public_id' => null,
            'authorization_key' => 'forged-admin-000000000001',
            'request_payload_hash' => hash('sha256', 'forged-admin-request'),
            'configuration_snapshot' => $forgedSnapshot,
            'configuration_snapshot_hash' => hash('sha256', $forgedSnapshot),
            'actor_type' => 'administrator',
            'actor_id' => $ownerId,
            'reason_code' => 'manual_service_grant',
            'correlation_id' => 'forged-admin-correlation-01',
            'created_at' => now('UTC'),
        ];
        $this->assertQueryRejected(fn () => DB::table('order_source_authorizations')->insert($base));

        $unsupported = $base;
        $unsupported['public_id'] = (string) Str::ulid();
        $unsupported['source_type'] = 'gift';
        $unsupported['authorization_key'] = 'unsupported-gift-00000001';
        $unsupported['actor_type'] = 'system';
        $unsupported['actor_id'] = null;
        $unsupported['reason_code'] = 'unsupported_gift';
        $unsupported['correlation_id'] = 'unsupported-gift-correlation-01';
        $this->assertQueryRejected(fn () => DB::table('order_source_authorizations')->insert($unsupported));

        self::assertSame(0, DB::table('order_source_authorizations')->count());
    }

    /** @param array{id:int,product_id:int,server_id:int} $offering */
    private function freeServiceEntitlement(int $userId, array $offering, string $suffix): string
    {
        $campaignCode = 'benefit.free.'.substr(hash('sha256', $suffix), 0, 12);
        $this->benefitCampaign(
            $campaignCode,
            BenefitCodeType::FreeService,
            $this->freeServiceDefinition($offering['id'], $offering['product_id'], $offering['server_id']),
            $suffix,
        );
        $issued = $this->benefitIssue($campaignCode, $suffix);
        $receipt = $this->app->make(BenefitCodeService::class)->redeem(
            new BenefitCodeRedemptionRequest(
                'free-order-source-'.substr(hash('sha256', $suffix), 0, 32),
                (string) $issued->items[0]->fullCode,
                $userId,
                $offering['id'],
                null,
                'free-source-'.substr(hash('sha256', $suffix), 0, 32),
            ),
            new BenefitCodeRedemptionContext($userId),
        );
        self::assertNotNull($receipt->entitlementPublicId);

        return $receipt->entitlementPublicId;
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
