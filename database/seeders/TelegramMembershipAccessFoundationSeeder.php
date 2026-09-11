<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TelegramMembershipAccessFoundationSeeder extends Seeder
{
    /** @requirement ONB-003 CHN-001 ACL-001 ACL-002 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('permissions')->upsert([[
            'code' => 'telegram.membership.manage',
            'module' => 'telegram',
            'risk_level' => 'high',
            'requires_approval' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);

        $roleId = DB::table('roles')->where('code', 'sales_content')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'telegram.membership.manage')->value('id');
        if ((! is_int($roleId) && ! is_string($roleId)) || (! is_int($permissionId) && ! is_string($permissionId))) {
            throw new RuntimeException('Telegram membership access seed references an unknown role or permission.');
        }

        DB::table('role_permissions')->upsert([[
            'role_id' => (int) $roleId,
            'permission_id' => (int) $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['role_id', 'permission_id'], ['updated_at']);
    }
}
