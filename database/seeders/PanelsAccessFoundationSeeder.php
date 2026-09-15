<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PanelsAccessFoundationSeeder extends Seeder
{
    /** @requirement PRV-001 SVC-006 ACL-001 ACL-002 ACL-003 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('permissions')->upsert([
            [
                'code' => 'servers.view',
                'module' => 'panels',
                'risk_level' => 'standard',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'panels.manage',
                'module' => 'panels',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'panels.manage_secrets',
                'module' => 'panels',
                'risk_level' => 'critical',
                'requires_approval' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'panels.test',
                'module' => 'panels',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'services.operate',
                'module' => 'provisioning',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'services.rotate_link',
                'module' => 'provisioning',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'services.retire',
                'module' => 'provisioning',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'services.import',
                'module' => 'provisioning',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'services.transfer_ownership',
                'module' => 'provisioning',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'services.repair',
                'module' => 'provisioning',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'services.grant_batch',
                'module' => 'provisioning',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);

        foreach (['support', 'technical', 'sales_content'] as $roleCode) {
            $this->grant($roleCode, 'servers.view', $now);
        }
        foreach (['panels.manage', 'panels.test', 'services.operate', 'services.rotate_link', 'services.retire'] as $permissionCode) {
            $this->grant('technical', $permissionCode, $now);
        }
    }

    private function grant(string $roleCode, string $permissionCode, mixed $now): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');

        if ((! is_int($roleId) && ! is_string($roleId))
            || (! is_int($permissionId) && ! is_string($permissionId))
        ) {
            throw new RuntimeException('Panel access seed references an unknown role or permission.');
        }

        DB::table('role_permissions')->upsert([
            [
                'role_id' => (int) $roleId,
                'permission_id' => (int) $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['role_id', 'permission_id'], ['updated_at']);
    }
}
