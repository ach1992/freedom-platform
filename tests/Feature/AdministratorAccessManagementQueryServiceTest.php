<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorAccessManagementQueryService;
use App\Modules\AccessControl\Application\AdministratorAccessTargetSearchDisposition;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ADM-002 ACL-001 ACL-002 SEC-002 SEC-003 DAT-003 QUA-001 */
final class AdministratorAccessManagementQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_owner_searches_and_resolves_actor_bound_target_without_internal_identifiers(): void
    {
        [$ownerUserId] = $this->administrator(true);
        [$targetUserId, , $targetPublicId] = $this->administrator();
        $botId = '123456789';
        DB::table('telegram_accounts')->insert([
            'user_id' => $targetUserId,
            'bot_id' => (int) $botId,
            'telegram_user_id' => 99887766,
            'username' => 'target_admin',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $service = $this->app->make(AdministratorAccessManagementQueryService::class);
        $result = $service->searchTarget($ownerUserId, $botId, '@target_admin');

        self::assertSame(AdministratorAccessTargetSearchDisposition::Matched, $result->disposition);
        self::assertNotNull($result->target);
        self::assertSame($targetPublicId, $result->target->userPublicId);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $result->target->selectionToken);
        self::assertNotSame((string) $targetUserId, $result->target->selectionToken);

        $resolved = $service->resolveTarget(
            $ownerUserId,
            $botId,
            $result->target->selectionToken,
        );
        self::assertSame($targetPublicId, $resolved->userPublicId);
    }

    public function test_approval_only_administrator_cannot_use_target_management_search(): void
    {
        [$ownerUserId, $ownerAdministratorId] = $this->administrator(true);
        [$approverUserId, $approverAdministratorId] = $this->administrator();
        [, , $targetPublicId] = $this->administrator();

        $this->grantPermissionToAdministrator(
            $ownerAdministratorId,
            $approverAdministratorId,
            'access.sensitive_actions.approve',
        );

        $service = $this->app->make(AdministratorAccessManagementQueryService::class);
        self::assertTrue($service->availableForUser($approverUserId));

        try {
            $service->searchTarget($approverUserId, '123456789', $targetPublicId);
            self::fail('Expected target-management visibility denial.');
        } catch (AuthorizationException $exception) {
            self::assertSame(
                'Administrator target-management visibility denied.',
                $exception->getMessage(),
            );
        }

        self::assertTrue($service->actorIsOwner($ownerUserId));
        self::assertFalse($service->actorIsOwner($approverUserId));
    }

    public function test_role_and_permission_selection_tokens_cannot_cross_actor_boundaries(): void
    {
        [, $ownerAdministratorId] = $this->administrator(true);
        [$firstUserId, $firstAdministratorId] = $this->administrator();
        [$secondUserId, $secondAdministratorId] = $this->administrator();

        foreach ([$firstAdministratorId, $secondAdministratorId] as $administratorId) {
            $this->grantPermissionToAdministrator(
                $ownerAdministratorId,
                $administratorId,
                'access.roles.manage',
            );
        }

        $service = $this->app->make(AdministratorAccessManagementQueryService::class);
        $roleToken = $service->roleSelectionToken($firstUserId, 'support');
        $permissionToken = $service->permissionSelectionToken(
            $firstUserId,
            'identity.customers.view',
        );

        self::assertSame('support', $service->resolveRoleSelectionToken($firstUserId, $roleToken));
        self::assertSame(
            'identity.customers.view',
            $service->resolvePermissionSelectionToken($firstUserId, $permissionToken),
        );

        try {
            $service->resolveRoleSelectionToken($secondUserId, $roleToken);
            self::fail('Expected actor-bound role selection failure.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        try {
            $service->resolvePermissionSelectionToken($secondUserId, $permissionToken);
            self::fail('Expected actor-bound permission selection failure.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
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

    private function grantPermissionToAdministrator(
        int $ownerAdministratorId,
        int $administratorId,
        string $permissionCode,
    ): void {
        $roleCode = 'custom.test_'.Str::lower(Str::random(10));
        $roleId = (int) DB::table('roles')->insertGetId([
            'code' => $roleCode,
            'name_translation_key' => 'roles.'.$roleCode,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $permissionId = (int) DB::table('permissions')->where('code', $permissionCode)->value('id');

        DB::table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => $ownerAdministratorId,
            'granted_at' => now('UTC'),
            'revoked_at' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
