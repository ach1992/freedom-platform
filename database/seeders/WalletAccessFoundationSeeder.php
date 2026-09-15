<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WalletAccessFoundationSeeder extends Seeder
{
    /** @requirement WAL-004 WAL-005 ACL-001 ACL-002 SEC-002 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('permissions')->upsert([
            [
                'code' => 'refunds.approve',
                'module' => 'wallet',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'refunds.override_destination',
                'module' => 'wallet',
                'risk_level' => 'critical',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'wallet.corrections.create',
                'module' => 'wallet',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'wallet.corrections.large',
                'module' => 'wallet',
                'risk_level' => 'critical',
                'requires_approval' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);

        $this->grant('finance', 'refunds.approve', $now);
        $this->grant('finance', 'wallet.corrections.create', $now);
        $this->grant('finance', 'wallet.corrections.large', $now);
    }

    private function grant(string $roleCode, string $permissionCode, mixed $now): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');

        if ((! is_int($roleId) && ! is_string($roleId)) || (! is_int($permissionId) && ! is_string($permissionId))) {
            throw new RuntimeException('Wallet access seed references an unknown role or permission.');
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
