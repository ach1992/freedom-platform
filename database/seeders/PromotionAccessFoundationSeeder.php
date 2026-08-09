<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PromotionAccessFoundationSeeder extends Seeder
{
    /** @requirement PRO-001 REF-001 ACL-001 ACL-002 SEC-002 */
    public function run(): void
    {
        $now = now('UTC');
        DB::table('permissions')->upsert([
            [
                'code' => 'promotions.rules.manage',
                'module' => 'promotions',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);

        foreach ([
            ['sales_content', 'promotions.rules.manage'],
        ] as [$roleCode, $permissionCode]) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');
            if ((! is_int($roleId) && ! is_string($roleId)) || (! is_int($permissionId) && ! is_string($permissionId))) {
                throw new RuntimeException('Promotion access seed references an unknown role or permission.');
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
}
