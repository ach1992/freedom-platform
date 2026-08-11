<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorAccessService;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\AccessControl\Domain\PermissionEffect;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ACL-001 ACL-002 SEC-002 QUA-001 */
final class AdministratorAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_owner_grants_multiple_roles_and_replay_does_not_duplicate_effects(): void
    {
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator();
        $service = $this->app->make(AdministratorAccessService::class);
        $authorizer = $this->app->make(AdministratorPermissionAuthorizer::class);
        $supportContext = $this->context($ownerId, 'access-role-request-0001');

        $support = $service->grantRole($administratorId, 'support', $supportContext);
        $replay = $service->grantRole($administratorId, 'support', $supportContext);
        $sales = $service->grantRole(
            $administratorId,
            'sales_content',
            $this->context($ownerId, 'access-role-request-0002'),
        );

        $authorizer->authorize($administratorId, 'identity.customers.view');
        $authorizer->authorize($administratorId, 'agents.applications.review');

        self::assertTrue($support->changed);
        self::assertTrue($replay->replayed);
        self::assertTrue($sales->changed);
        self::assertSame(2, DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->whereNull('revoked_at')->count());
        self::assertSame(3, (int) DB::table('administrators')->where('id', $administratorId)->value('permission_version'));
        self::assertSame(2, DB::table('audit_logs')->where('action', 'access.role.grant')->count());
    }

    public function test_deny_allow_and_inherit_apply_over_the_multi_role_union(): void
    {
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator();
        $service = $this->app->make(AdministratorAccessService::class);
        $authorizer = $this->app->make(AdministratorPermissionAuthorizer::class);

        $service->grantRole(
            $administratorId,
            'sales_content',
            $this->context($ownerId, 'access-role-request-0010'),
        );
        $service->setPermissionOverride(
            $administratorId,
            'agents.applications.review',
            PermissionEffect::Deny,
            $this->context($ownerId, 'access-override-request-0010'),
        );
        $this->assertDenied($authorizer, $administratorId, 'agents.applications.review');

        $service->setPermissionOverride(
            $administratorId,
            'agents.applications.review',
            PermissionEffect::Allow,
            $this->context($ownerId, 'access-override-request-0011'),
        );
        $authorizer->authorize($administratorId, 'agents.applications.review');

        $service->setPermissionOverride(
            $administratorId,
            'agents.applications.review',
            PermissionEffect::Inherit,
            $this->context($ownerId, 'access-override-request-0012'),
        );
        $authorizer->authorize($administratorId, 'agents.applications.review');

        $service->setPermissionOverride(
            $administratorId,
            'access.sensitive_actions.approve',
            PermissionEffect::Allow,
            $this->context($ownerId, 'access-override-request-0013'),
        );
        $authorizer->authorize($administratorId, 'access.sensitive_actions.approve');

        $service->setPermissionOverride(
            $administratorId,
            'access.sensitive_actions.approve',
            PermissionEffect::Inherit,
            $this->context($ownerId, 'access-override-request-0014'),
        );
        $this->assertDenied($authorizer, $administratorId, 'access.sensitive_actions.approve');

        self::assertSame(0, DB::table('administrator_permission_overrides')->where('administrator_id', $administratorId)->count());
        self::assertSame(7, (int) DB::table('administrators')->where('id', $administratorId)->value('permission_version'));
        self::assertSame(5, DB::table('audit_logs')->where('action', 'access.permission.override')->count());
    }

    public function test_role_revoke_replay_and_reactivation_preserve_one_assignment_row(): void
    {
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator();
        $service = $this->app->make(AdministratorAccessService::class);
        $authorizer = $this->app->make(AdministratorPermissionAuthorizer::class);

        $service->grantRole(
            $administratorId,
            'support',
            $this->context($ownerId, 'access-role-request-0020'),
        );
        $revokeContext = $this->context($ownerId, 'access-role-request-0021');
        $revoked = $service->revokeRole($administratorId, 'support', $revokeContext);
        $replay = $service->revokeRole($administratorId, 'support', $revokeContext);
        $this->assertDenied($authorizer, $administratorId, 'identity.customers.view');

        $reactivated = $service->grantRole(
            $administratorId,
            'support',
            $this->context($ownerId, 'access-role-request-0022'),
        );
        $authorizer->authorize($administratorId, 'identity.customers.view');

        self::assertTrue($revoked->changed);
        self::assertTrue($replay->replayed);
        self::assertTrue($reactivated->changed);
        self::assertSame(1, DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->count());
        self::assertNull(DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->value('revoked_at'));
        self::assertSame(4, (int) DB::table('administrators')->where('id', $administratorId)->value('permission_version'));
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.role.revoke')->count());
    }

    public function test_unauthorized_actor_cannot_mutate_roles_or_audit_state(): void
    {
        $actorId = $this->administrator();
        $administratorId = $this->administrator();
        $service = $this->app->make(AdministratorAccessService::class);

        try {
            $service->grantRole(
                $administratorId,
                'support',
                $this->context($actorId, 'access-role-request-0030'),
            );
            self::fail('Expected access management authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->count());
            self::assertSame(0, DB::table('audit_logs')->where('target_type', 'administrator')->count());
            self::assertSame(1, (int) DB::table('administrators')->where('id', $administratorId)->value('permission_version'));
        }
    }

    public function test_delegated_manager_cannot_self_escalate_or_delegate_permissions_it_lacks(): void
    {
        $ownerId = $this->administrator(true);
        $managerId = $this->administrator();
        $administratorId = $this->administrator();
        $service = $this->app->make(AdministratorAccessService::class);

        $this->addRolePermission('support', 'access.roles.manage');
        $this->addRolePermission('support', 'access.permissions.override');
        $service->grantRole(
            $managerId,
            'support',
            $this->context($ownerId, 'access-role-request-0040'),
        );
        $service->grantRole(
            $administratorId,
            'support',
            $this->context($managerId, 'access-role-request-0041'),
        );

        try {
            $service->grantRole(
                $administratorId,
                'sales_content',
                $this->context($managerId, 'access-role-request-0042'),
            );
            self::fail('Expected delegation ceiling failure.');
        } catch (AuthorizationException) {
            self::assertSame(1, DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->whereNull('revoked_at')->count());
        }

        $service->setPermissionOverride(
            $administratorId,
            'agents.applications.review',
            PermissionEffect::Deny,
            $this->context($managerId, 'access-override-request-0040'),
        );

        try {
            $service->setPermissionOverride(
                $administratorId,
                'agents.applications.review',
                PermissionEffect::Allow,
                $this->context($managerId, 'access-override-request-0041'),
            );
            self::fail('Expected override delegation ceiling failure.');
        } catch (AuthorizationException) {
            self::assertSame('deny', DB::table('administrator_permission_overrides')->where('administrator_id', $administratorId)->value('effect'));
        }

        try {
            $service->revokeRole(
                $managerId,
                'support',
                $this->context($managerId, 'access-role-request-0043'),
            );
            self::fail('Expected self-management failure.');
        } catch (AuthorizationException) {
            self::assertNull(DB::table('administrator_role_assignments')->where('administrator_id', $managerId)->value('revoked_at'));
        }
    }

    public function test_owner_target_is_protected_and_fingerprint_conflicts_fail_closed(): void
    {
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator();
        $service = $this->app->make(AdministratorAccessService::class);

        try {
            $service->grantRole(
                $ownerId,
                'support',
                $this->context($ownerId, 'access-role-request-0050'),
            );
            self::fail('Expected protected Owner failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('administrator_role_assignments')->where('administrator_id', $ownerId)->count());
        }

        $context = $this->context($ownerId, 'access-role-request-0051');
        $service->grantRole($administratorId, 'support', $context);

        try {
            $service->grantRole($administratorId, 'sales_content', $context);
            self::fail('Expected request fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Access mutation fingerprint conflict.', $exception->getMessage());
            self::assertSame(1, DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->count());
            self::assertSame(2, (int) DB::table('administrators')->where('id', $administratorId)->value('permission_version'));
        }
    }

    private function assertDenied(
        AdministratorPermissionAuthorizer $authorizer,
        int $administratorId,
        string $permissionCode,
    ): void {
        try {
            $authorizer->authorize($administratorId, $permissionCode);
            self::fail('Expected permission denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function addRolePermission(string $roleCode, string $permissionCode): void
    {
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
        $permissionId = (int) DB::table('permissions')->where('code', $permissionCode)->value('id');
        $now = now('UTC');

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function administrator(bool $owner = false): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user(),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(
        int $actorAdministratorId,
        string $requestFingerprint,
    ): AccessChangeContext {
        return new AccessChangeContext(
            $requestFingerprint,
            str_replace('request', 'correlation', $requestFingerprint),
            'access_test_change',
            'Access management test change.',
            $actorAdministratorId,
        );
    }
}
