<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OperationsAccessFoundationSeeder extends Seeder
{
    /** @requirement OPS-001 OPS-003 ACL-001 ACL-002 SEC-002 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('permissions')->upsert([
            $this->permission('operations.view', 'standard', $now),
            $this->permission('operations.alerts.manage', 'high', $now),
            $this->permission('operations.actions.execute', 'high', $now),
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);

        foreach ([
            'operations.view',
            'operations.alerts.manage',
            'operations.actions.execute',
        ] as $permissionCode) {
            $this->grant('technical', $permissionCode, $now);
        }
    }

    /** @return array{code:string,module:string,risk_level:string,requires_approval:bool,created_at:mixed,updated_at:mixed} */
    private function permission(string $code, string $riskLevel, mixed $now): array
    {
        return [
            'code' => $code,
            'module' => 'operations',
            'risk_level' => $riskLevel,
            'requires_approval' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function grant(string $roleCode, string $permissionCode, mixed $now): void
    {
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');
        if ((! is_int($roleId) && ! is_string($roleId))
            || (! is_int($permissionId) && ! is_string($permissionId))
        ) {
            throw new RuntimeException('Operations access seed references an unknown role or permission.');
        }

        DB::table('role_permissions')->upsert([[
            'role_id' => (int) $roleId,
            'permission_id' => (int) $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['role_id', 'permission_id'], ['updated_at']);
    }
}
