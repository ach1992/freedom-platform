<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement SVC-006 ACL-001 ACL-002 ACL-003 SEC-002 */
final class ServiceLifecyclePermissionSeedTest extends TestCase
{
    use DatabaseTruncation;

    public function test_technical_role_receives_canonical_service_lifecycle_permissions(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);

        $technicalRoleId = (int) DB::table('roles')->where('code', 'technical')->value('id');
        self::assertGreaterThan(0, $technicalRoleId);

        foreach (['services.operate', 'services.rotate_link', 'services.retire'] as $permissionCode) {
            $permission = DB::table('permissions')->where('code', $permissionCode)->first([
                'id', 'module', 'risk_level', 'requires_approval',
            ]);
            self::assertNotNull($permission);
            self::assertSame('provisioning', $permission->module);
            self::assertSame('high', $permission->risk_level);
            self::assertFalse((bool) $permission->requires_approval);
            self::assertTrue(DB::table('role_permissions')
                ->where('role_id', $technicalRoleId)
                ->where('permission_id', (int) $permission->id)
                ->exists());
        }
    }
}
