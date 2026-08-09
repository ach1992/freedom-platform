<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PaymentEligibilityAccessFoundationSeeder extends Seeder
{
    /** @requirement PAY-001 ACL-002 SEC-001 SEC-002 */
    public function run(): void
    {
        $now = now('UTC');
        DB::table('permissions')->upsert([
            [
                'code' => 'payments.eligibility.manage',
                'module' => 'payments',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);

        $roleId = DB::table('roles')->where('code', 'finance')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'payments.eligibility.manage')->value('id');
        if ((! is_int($roleId) && ! is_string($roleId)) || (! is_int($permissionId) && ! is_string($permissionId))) {
            throw new RuntimeException('Payment eligibility access seed references an unknown role or permission.');
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
