<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorRoleCatalogService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ADM-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-001 */
final class AdministratorRoleCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_owner_creates_custom_role_and_manages_existing_permission_idempotently(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(AdministratorRoleCatalogService::class);

        $createContext = $this->context($ownerId, 'custom-role-create-0001');
        $created = $service->createCustomRole('custom.ops_read', $createContext);
        $createReplay = $service->createCustomRole('custom.ops_read', $createContext);

        $permissionContext = $this->context($ownerId, 'custom-role-permission-0001');
        $granted = $service->setCustomRolePermission(
            'custom.ops_read',
            'identity.customers.view',
            true,
            $permissionContext,
        );
        $grantReplay = $service->setCustomRolePermission(
            'custom.ops_read',
            'identity.customers.view',
            true,
            $permissionContext,
        );

        self::assertTrue($created->changed);
        self::assertTrue($createReplay->replayed);
        self::assertTrue($granted->changed);
        self::assertTrue($grantReplay->replayed);
        self::assertSame(1, DB::table('roles')->where('code', 'custom.ops_read')->count());
        self::assertSame(1, DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.code', 'custom.ops_read')
            ->where('permissions.code', 'identity.customers.view')
            ->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.role_definition.create')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.role_definition.permission')->count());
    }

    public function test_custom_role_definition_changes_invalidate_active_assignee_permission_versions_once(): void
    {
        $ownerId = $this->administrator(true);
        $firstAdministratorId = $this->administrator();
        $secondAdministratorId = $this->administrator();
        $revokedAdministratorId = $this->administrator();
        $service = $this->app->make(AdministratorRoleCatalogService::class);

        $service->createCustomRole(
            'custom.invalidate_test',
            $this->context($ownerId, 'custom-role-invalidate-create-0001'),
        );
        $this->assignRole($firstAdministratorId, 'custom.invalidate_test');
        $this->assignRole($secondAdministratorId, 'custom.invalidate_test');
        $this->assignRole($revokedAdministratorId, 'custom.invalidate_test');
        DB::table('administrator_role_assignments')
            ->where('administrator_id', $revokedAdministratorId)
            ->where('role_id', (int) DB::table('roles')->where('code', 'custom.invalidate_test')->value('id'))
            ->update([
                'revoked_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

        $permissionContext = $this->context($ownerId, 'custom-role-invalidate-permission-0001');
        $service->setCustomRolePermission(
            'custom.invalidate_test',
            'identity.customers.view',
            true,
            $permissionContext,
        );

        self::assertSame(2, (int) DB::table('administrators')->where('id', $firstAdministratorId)->value('permission_version'));
        self::assertSame(2, (int) DB::table('administrators')->where('id', $secondAdministratorId)->value('permission_version'));
        self::assertSame(1, (int) DB::table('administrators')->where('id', $revokedAdministratorId)->value('permission_version'));

        $service->setCustomRolePermission(
            'custom.invalidate_test',
            'identity.customers.view',
            true,
            $permissionContext,
        );
        self::assertSame(2, (int) DB::table('administrators')->where('id', $firstAdministratorId)->value('permission_version'));
        self::assertSame(2, (int) DB::table('administrators')->where('id', $secondAdministratorId)->value('permission_version'));

        $statusContext = $this->context($ownerId, 'custom-role-invalidate-status-0001');
        $service->setCustomRoleActive(
            'custom.invalidate_test',
            false,
            $statusContext,
        );
        self::assertSame(3, (int) DB::table('administrators')->where('id', $firstAdministratorId)->value('permission_version'));
        self::assertSame(3, (int) DB::table('administrators')->where('id', $secondAdministratorId)->value('permission_version'));
        self::assertSame(1, (int) DB::table('administrators')->where('id', $revokedAdministratorId)->value('permission_version'));

        $service->setCustomRoleActive(
            'custom.invalidate_test',
            false,
            $statusContext,
        );
        self::assertSame(3, (int) DB::table('administrators')->where('id', $firstAdministratorId)->value('permission_version'));
        self::assertSame(3, (int) DB::table('administrators')->where('id', $secondAdministratorId)->value('permission_version'));
    }

    public function test_reused_fingerprint_cannot_be_rebound_to_another_permission_or_value(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(AdministratorRoleCatalogService::class);
        $service->createCustomRole(
            'custom.support_lite',
            $this->context($ownerId, 'custom-role-create-0010'),
        );

        $context = $this->context($ownerId, 'custom-role-permission-0010');
        $service->setCustomRolePermission(
            'custom.support_lite',
            'identity.customers.view',
            true,
            $context,
        );

        try {
            $service->setCustomRolePermission(
                'custom.support_lite',
                'agents.applications.review',
                true,
                $context,
            );
            self::fail('Expected role mutation fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Role mutation fingerprint conflict.', $exception->getMessage());
        }

        try {
            $service->setCustomRolePermission(
                'custom.support_lite',
                'identity.customers.view',
                false,
                $context,
            );
            self::fail('Expected role mutation value conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Role mutation fingerprint conflict.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.code', 'custom.support_lite')
            ->count());
    }

    public function test_system_roles_are_immutable_and_non_owner_delegation_cannot_escalate(): void
    {
        $ownerId = $this->administrator(true);
        $managerId = $this->administrator();
        $service = $this->app->make(AdministratorRoleCatalogService::class);

        $this->grantRolePermission('support', 'access.roles.manage');
        $this->assignRole($managerId, 'support');

        try {
            $service->setCustomRolePermission(
                'support',
                'identity.customers.view',
                false,
                $this->context($ownerId, 'custom-role-system-0001'),
            );
            self::fail('Expected system-role protection.');
        } catch (RuntimeException|AuthorizationException) {
            self::assertTrue(true);
        }

        $service->createCustomRole(
            'custom.delegated',
            $this->context($managerId, 'custom-role-create-0020'),
        );

        try {
            $service->setCustomRolePermission(
                'custom.delegated',
                'access.sensitive_actions.approve',
                true,
                $this->context($managerId, 'custom-role-permission-0020'),
            );
            self::fail('Expected delegation ceiling failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where('roles.code', 'custom.delegated')
                ->count());
        }
    }

    private function administrator(bool $owner = false): int
    {
        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user(),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function user(): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'locale' => 'fa',
            'account_type' => 'customer',
            'account_status' => 'active',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function context(int $actorAdministratorId, string $fingerprint): AccessChangeContext
    {
        return new AccessChangeContext(
            requestFingerprint: $fingerprint,
            correlationId: 'role-catalog-test-'.substr(hash('sha256', $fingerprint), 0, 40),
            reasonCode: 'role_catalog_test',
            reason: 'Role catalog test mutation.',
            actorAdministratorId: $actorAdministratorId,
        );
    }

    private function grantRolePermission(string $roleCode, string $permissionCode): void
    {
        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => (int) DB::table('roles')->where('code', $roleCode)->value('id'),
            'permission_id' => (int) DB::table('permissions')->where('code', $permissionCode)->value('id'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function assignRole(int $administratorId, string $roleCode): void
    {
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => (int) DB::table('roles')->where('code', $roleCode)->value('id'),
            'granted_by_administrator_id' => null,
            'granted_at' => now('UTC'),
            'revoked_at' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
