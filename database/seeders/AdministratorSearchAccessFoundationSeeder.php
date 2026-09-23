<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AdministratorSearchAccessFoundationSeeder extends Seeder
{
    public const ORDER_PERMISSION = 'administration.search.orders';

    public const PAYMENT_PERMISSION = 'administration.search.payments';

    public const PAYMENT_EVIDENCE_PERMISSION = 'administration.search.payment_evidence';

    public const SERVICE_PERMISSION = 'administration.search.services';

    /** @requirement ADM-001 ACL-001 ACL-002 SEC-002 SEC-003 */
    public function run(): void
    {
        $now = now('UTC');

        $definitions = [
            [self::ORDER_PERMISSION, 'standard'],
            [self::PAYMENT_PERMISSION, 'standard'],
            [self::PAYMENT_EVIDENCE_PERMISSION, 'high'],
            [self::SERVICE_PERMISSION, 'standard'],
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
                self::ORDER_PERMISSION,
                self::PAYMENT_PERMISSION,
                self::PAYMENT_EVIDENCE_PERMISSION,
            ],
            'support' => [
                self::ORDER_PERMISSION,
                self::PAYMENT_PERMISSION,
                self::SERVICE_PERMISSION,
            ],
            'technical' => [
                self::ORDER_PERMISSION,
                self::SERVICE_PERMISSION,
            ],
            'sales_content' => [
                self::ORDER_PERMISSION,
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
