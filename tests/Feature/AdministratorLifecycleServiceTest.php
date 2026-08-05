<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorLifecycleService;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ADM-001 ACL-002 SEC-002 QUA-001 */
final class AdministratorLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_owner_suspends_and_reactivates_administrator_with_immediate_authorization_invalidation(): void
    {
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator();
        $this->assignRole($administratorId, 'support');
        $service = $this->app->make(AdministratorLifecycleService::class);
        $authorizer = $this->app->make(AdministratorPermissionAuthorizer::class);
        $authorizer->authorize($administratorId, 'identity.customers.view');
        $suspendContext = $this->context($ownerId, 'administrator-suspend-request-0001');

        $suspended = $service->suspend($administratorId, $suspendContext);
        $replay = $service->suspend($administratorId, $suspendContext);
        $this->assertDenied($authorizer, $administratorId, 'identity.customers.view');

        self::assertTrue($suspended->changed);
        self::assertTrue($replay->replayed);
        self::assertDatabaseHas('administrators', [
            'id' => $administratorId,
            'status' => 'suspended',
            'permission_version' => 2,
            'status_reason_code' => 'administrator_lifecycle_test',
        ]);
        self::assertNotNull(DB::table('administrators')->where('id', $administratorId)->value('suspended_at'));
        self::assertNull(DB::table('administrators')->where('id', $administratorId)->value('last_authenticated_at'));
        self::assertNull(DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->value('revoked_at'));
        self::assertSame(1, DB::table('administrator_status_histories')->where('administrator_id', $administratorId)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.administrator.suspended')->count());

        $reactivated = $service->reactivate(
            $administratorId,
            $this->context($ownerId, 'administrator-reactivate-request-0001'),
        );
        $authorizer->authorize($administratorId, 'identity.customers.view');

        self::assertTrue($reactivated->changed);
        self::assertDatabaseHas('administrators', [
            'id' => $administratorId,
            'status' => 'active',
            'permission_version' => 3,
        ]);
        self::assertNull(DB::table('administrators')->where('id', $administratorId)->value('suspended_at'));
        self::assertNull(DB::table('administrators')->where('id', $administratorId)->value('last_authenticated_at'));
        self::assertSame(2, DB::table('administrator_status_histories')->where('administrator_id', $administratorId)->count());
    }

    public function test_revoke_is_terminal_and_removes_roles_and_overrides_atomically(): void
    {
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator();
        $this->assignRole($administratorId, 'support');
        $this->override($administratorId, 'agents.applications.review', 'deny');
        $service = $this->app->make(AdministratorLifecycleService::class);
        $authorizer = $this->app->make(AdministratorPermissionAuthorizer::class);

        $revoked = $service->revoke(
            $administratorId,
            $this->context($ownerId, 'administrator-revoke-request-0001'),
        );
        $this->assertDenied($authorizer, $administratorId, 'identity.customers.view');

        self::assertTrue($revoked->changed);
        self::assertSame(1, $revoked->after['revoked_role_count']);
        self::assertSame(1, $revoked->after['removed_override_count']);
        self::assertDatabaseHas('administrators', [
            'id' => $administratorId,
            'status' => 'revoked',
            'permission_version' => 2,
        ]);
        self::assertNotNull(DB::table('administrators')->where('id', $administratorId)->value('revoked_at'));
        self::assertNotNull(DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->value('revoked_at'));
        self::assertSame(0, DB::table('administrator_permission_overrides')->where('administrator_id', $administratorId)->count());
        self::assertDatabaseHas('administrator_status_histories', [
            'administrator_id' => $administratorId,
            'from_status' => 'active',
            'to_status' => 'revoked',
            'permission_version' => 2,
            'revoked_role_count' => 1,
            'removed_override_count' => 1,
        ]);

        try {
            $service->reactivate(
                $administratorId,
                $this->context($ownerId, 'administrator-reactivate-request-0010'),
            );
            self::fail('Expected revoked administrator terminal-state failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Administrator lifecycle transition is not allowed.', $exception->getMessage());
            self::assertSame('revoked', DB::table('administrators')->where('id', $administratorId)->value('status'));
            self::assertSame(1, DB::table('administrator_status_histories')->where('administrator_id', $administratorId)->count());
        }
    }

    public function test_owner_target_and_self_management_are_protected(): void
    {
        $ownerId = $this->administrator(true);
        $managerId = $this->administrator();
        $this->addRolePermission('support', 'admins.accounts.manage');
        $this->assignRole($managerId, 'support');
        $service = $this->app->make(AdministratorLifecycleService::class);

        try {
            $service->suspend(
                $ownerId,
                $this->context($managerId, 'administrator-suspend-owner-0001'),
            );
            self::fail('Expected Owner lifecycle protection.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Owner lifecycle changes require the ownership-transfer flow.', $exception->getMessage());
            self::assertSame('active', DB::table('administrators')->where('id', $ownerId)->value('status'));
        }

        try {
            $service->suspend(
                $managerId,
                $this->context($managerId, 'administrator-suspend-self-0001'),
            );
            self::fail('Expected administrator self-management protection.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Administrator self-management is not allowed.', $exception->getMessage());
            self::assertSame('active', DB::table('administrators')->where('id', $managerId)->value('status'));
            self::assertSame(0, DB::table('administrator_status_histories')->count());
        }
    }

    public function test_unauthorized_actor_cannot_change_administrator_lifecycle(): void
    {
        $actorId = $this->administrator();
        $administratorId = $this->administrator();
        $service = $this->app->make(AdministratorLifecycleService::class);

        try {
            $service->suspend(
                $administratorId,
                $this->context($actorId, 'administrator-suspend-denied-0001'),
            );
            self::fail('Expected administrator lifecycle authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame('active', DB::table('administrators')->where('id', $administratorId)->value('status'));
            self::assertSame(1, (int) DB::table('administrators')->where('id', $administratorId)->value('permission_version'));
            self::assertSame(0, DB::table('administrator_status_histories')->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'access.administrator.suspended')->count());
        }
    }

    public function test_request_fingerprint_cannot_be_rebound_to_another_administrator(): void
    {
        $ownerId = $this->administrator(true);
        $firstAdministratorId = $this->administrator();
        $secondAdministratorId = $this->administrator();
        $service = $this->app->make(AdministratorLifecycleService::class);
        $context = $this->context($ownerId, 'administrator-suspend-conflict-0001');

        $service->suspend($firstAdministratorId, $context);

        try {
            $service->suspend($secondAdministratorId, $context);
            self::fail('Expected administrator lifecycle fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Access mutation fingerprint conflict.', $exception->getMessage());
            self::assertSame('suspended', DB::table('administrators')->where('id', $firstAdministratorId)->value('status'));
            self::assertSame('active', DB::table('administrators')->where('id', $secondAdministratorId)->value('status'));
            self::assertSame(1, DB::table('administrator_status_histories')->count());
        }
    }

    private function assertDenied(
        AdministratorPermissionAuthorizer $authorizer,
        int $administratorId,
        string $permissionCode,
    ): void {
        try {
            $authorizer->authorize($administratorId, $permissionCode);
            self::fail('Expected administrator permission denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function assignRole(int $administratorId, string $roleCode): void
    {
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
        $now = now('UTC');
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function override(int $administratorId, string $permissionCode, string $effect): void
    {
        $permissionId = (int) DB::table('permissions')->where('code', $permissionCode)->value('id');
        $now = now('UTC');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => $effect,
            'changed_by_administrator_id' => null,
            'reason_code' => 'administrator_lifecycle_test',
            'reason' => 'Administrator lifecycle test override.',
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

    private function context(int $actorAdministratorId, string $fingerprint): AccessChangeContext
    {
        return new AccessChangeContext(
            $fingerprint,
            str_replace('request', 'correlation', $fingerprint),
            'administrator_lifecycle_test',
            'Administrator lifecycle test operation.',
            $actorAdministratorId,
        );
    }
}
