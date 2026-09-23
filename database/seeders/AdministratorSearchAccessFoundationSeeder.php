<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\AccessControl\Application\AdministratorSearchPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AdministratorSearchAccessFoundationSeeder extends Seeder
{
    /** @requirement ADM-001 ACL-001 ACL-002 SEC-002 SEC-003 */
    public function run(): void
    {
        $now = now('UTC');

        $definitions = [
            [AdministratorSearchPermissions::ORDER, 'standard'],
            [AdministratorSearchPermissions::PAYMENT, 'standard'],
            [AdministratorSearchPermissions::PAYMENT_EVIDENCE, 'high'],
            [AdministratorSearchPermissions::SERVICE, 'standard'],
        ];

        foreach ($definitions as [$code, $risk]) {
            DB::table('permissions')->upsert([[
                'code' => $code,
                'module' => 'administration',
                'risk_level' => $risk,
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);
        }

        $grants = [
            'finance' => [
                AdministratorSearchPermissions::ORDER,
                AdministratorSearchPermissions::PAYMENT,
                AdministratorSearchPermissions::PAYMENT_EVIDENCE,
            ],
            'support' => [
                AdministratorSearchPermissions::ORDER,
                AdministratorSearchPermissions::PAYMENT,
                AdministratorSearchPermissions::SERVICE,
            ],
            'technical' => [
                AdministratorSearchPermissions::ORDER,
                AdministratorSearchPermissions::SERVICE,
            ],
            'sales_content' => [
                AdministratorSearchPermissions::ORDER,
            ],
        ];

        foreach ($grants as $roleCode => $permissionCodes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if (! is_int($roleId) && ! is_string($roleId)) {
                throw new RuntimeException('Administrator search access seed references an unknown role.');
            }

            foreach ($permissionCodes as $permissionCode) {
                $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');
                if (! is_int($permissionId) && ! is_string($permissionId)) {
                    throw new RuntimeException('Administrator search access seed references an unknown permission.');
                }

                DB::table('role_permissions')->upsert([[
                    'role_id' => (int) $roleId,
                    'permission_id' => (int) $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['role_id', 'permission_id'], ['updated_at']);
            }
        }
    }
}
