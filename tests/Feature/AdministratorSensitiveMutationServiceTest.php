<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorSensitiveMutation;
use App\Modules\AccessControl\Application\AdministratorSensitiveMutationService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement ADM-002 ACL-001 ACL-002 ACL-003 SEC-002 DAT-003 QUA-001 */
final class AdministratorSensitiveMutationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_owner_can_request_self_approve_and_execute_exact_role_mutation_once(): void
    {
        [$ownerUserId] = $this->administrator(true);
        [$targetUserId, $targetAdministratorId, $targetPublicId] = $this->administrator();
        $service = $this->app->make(AdministratorSensitiveMutationService::class);
        $mutation = new AdministratorSensitiveMutation(
            AdministratorSensitiveMutation::ROLE_GRANT,
            $targetPublicId,
            'support',
        );

        $approval = $service->request($ownerUserId, $mutation, 'Grant support role.', 'request-owner-role-0001');
        $service->approve($ownerUserId, $approval->approvalId, 'Approved by Owner.', 'approve-owner-role-0001');
        $executed = $service->execute(
            $ownerUserId,
            $approval->approvalId,
            $mutation,
            'Execute approved support role grant.',
            'execute-owner-role-0001',
        );
        $replay = $service->execute(
            $ownerUserId,
            $approval->approvalId,
            $mutation,
            'Execute approved support role grant.',
            'execute-owner-role-0001',
        );

        self::assertTrue($executed->changed);
        self::assertTrue($replay->replayed);
        self::assertSame(1, DB::table('administrator_role_assignments')
            ->where('administrator_id', $targetAdministratorId)
            ->whereNull('revoked_at')
            ->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.role.grant')->count());
        self::assertNotNull(DB::table('sensitive_action_approvals')
            ->where('id', $approval->approvalId)
            ->value('consumed_at'));
        self::assertSame($targetUserId, (int) DB::table('administrators')
            ->where('id', $targetAdministratorId)
            ->value('user_id'));
    }

    public function test_non_owner_request_requires_independent_approver_and_exact_mutation_binding(): void
    {
        [$ownerUserId] = $this->administrator(true);
        [$managerUserId, $managerAdministratorId] = $this->administrator();
        [, $targetAdministratorId, $targetPublicId] = $this->administrator();
        $this->grantRolePermission('support', 'access.roles.manage');
        $this->grantRolePermission('support', 'identity.customers.view');
        $this->assignRole($managerAdministratorId, 'support');

        $service = $this->app->make(AdministratorSensitiveMutationService::class);
        $mutation = new AdministratorSensitiveMutation(
            AdministratorSensitiveMutation::ROLE_GRANT,
            $targetPublicId,
            'support',
        );
        $approval = $service->request($managerUserId, $mutation, 'Delegate support role.', 'request-manager-role-0001');

        try {
            $service->approve($managerUserId, $approval->approvalId, 'Self approval.', 'approve-manager-self-0001');
            self::fail('Expected independent approval protection.');
        } catch (AuthorizationException) {
            self::assertSame('pending', DB::table('sensitive_action_approvals')
                ->where('id', $approval->approvalId)
                ->value('state'));
        }

        $service->approve($ownerUserId, $approval->approvalId, 'Owner approval.', 'approve-manager-owner-0001');

        $different = new AdministratorSensitiveMutation(
            AdministratorSensitiveMutation::ROLE_GRANT,
            $targetPublicId,
            'sales_content',
        );
        try {
            $service->execute(
                $managerUserId,
                $approval->approvalId,
                $different,
                'Wrong approved operation.',
                'execute-manager-wrong-0001',
            );
            self::fail('Expected exact approval binding failure.');
        } catch (\RuntimeException) {
            self::assertSame(0, DB::table('administrator_role_assignments')
                ->where('administrator_id', $targetAdministratorId)
                ->count());
        }

        $service->execute(
            $managerUserId,
            $approval->approvalId,
            $mutation,
            'Execute approved role grant.',
            'execute-manager-role-0001',
        );
        self::assertSame(1, DB::table('administrator_role_assignments')
            ->where('administrator_id', $targetAdministratorId)
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_permission_override_execution_is_atomic_with_approval_consumption(): void
    {
        [$ownerUserId] = $this->administrator(true);
        [, $targetAdministratorId, $targetPublicId] = $this->administrator();
        $service = $this->app->make(AdministratorSensitiveMutationService::class);
        $mutation = new AdministratorSensitiveMutation(
            AdministratorSensitiveMutation::PERMISSION_DENY,
            $targetPublicId,
            permissionCode: 'identity.customers.view',
        );

        $approval = $service->request($ownerUserId, $mutation, 'Deny customer read.', 'request-override-0001');
        $service->approve($ownerUserId, $approval->approvalId, 'Approve deny.', 'approve-override-0001');
        $result = $service->execute(
            $ownerUserId,
            $approval->approvalId,
            $mutation,
            'Apply deny.',
            'execute-override-0001',
        );

        self::assertTrue($result->changed);
        self::assertDatabaseHas('administrator_permission_overrides', [
            'administrator_id' => $targetAdministratorId,
            'effect' => 'deny',
        ]);
        self::assertNotNull(DB::table('sensitive_action_approvals')
            ->where('id', $approval->approvalId)
            ->value('consumed_at'));
    }

    /** @return array{0:int,1:int,2:string} */
    private function administrator(bool $owner = false): array
    {
        $publicId = (string) Str::ulid();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'locale' => 'fa',
            'account_type' => 'customer',
            'account_status' => 'active',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return [$userId, $administratorId, $publicId];
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
