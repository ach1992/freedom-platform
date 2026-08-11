<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CatalogAccessFoundationSeeder extends Seeder
{
    /** @requirement CAT-001 CAT-002 ACL-001 ACL-002 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('permissions')->upsert([
            [
                'code' => 'catalog.view',
                'module' => 'catalog',
                'risk_level' => 'standard',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'catalog.manage',
                'module' => 'catalog',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);

        foreach (['finance', 'support', 'technical', 'sales_content'] as $roleCode) {
            $this->grant($roleCode, 'catalog.view', $now);
        }
        $this->grant('sales_content', 'catalog.manage', $now);
    }

    private function grant(string $roleCode, string $permissionCode, mixed $now): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');

        if ((! is_int($roleId) && ! is_string($roleId)) || (! is_int($permissionId) && ! is_string($permissionId))) {
            throw new RuntimeException('Catalog access seed references an unknown role or permission.');
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
