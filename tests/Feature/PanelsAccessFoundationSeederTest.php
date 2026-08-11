<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement PRV-001 ACL-001 ACL-002 ACL-003 QUA-001 */
final class PanelsAccessFoundationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_permissions_and_role_grants_are_exact_and_idempotent(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);

        $permissions = DB::table('permissions')
            ->where('module', 'panels')
            ->orderBy('code')
            ->get(['code', 'risk_level', 'requires_approval']);

        self::assertSame([
            'panels.manage',
            'panels.manage_secrets',
            'panels.test',
            'servers.view',
        ], $permissions->pluck('code')->all());
        self::assertSame(1, (int) DB::table('permissions')
            ->where('code', 'panels.manage_secrets')
            ->value('requires_approval'));
        self::assertSame(0, (int) DB::table('permissions')
            ->where('code', 'panels.test')
            ->value('requires_approval'));

        $technicalId = (int) DB::table('roles')->where('code', 'technical')->value('id');
        self::assertSame([
            'panels.manage',
            'panels.test',
            'servers.view',
        ], $this->rolePanelPermissions($technicalId));

        foreach (['support', 'sales_content'] as $roleCode) {
            $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
            self::assertSame(['servers.view'], $this->rolePanelPermissions($roleId));
        }

        $financeId = (int) DB::table('roles')->where('code', 'finance')->value('id');
        self::assertSame([], $this->rolePanelPermissions($financeId));
        self::assertSame(0, DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.code', 'panels.manage_secrets')
            ->count());
    }

    /** @return list<string> */
    private function rolePanelPermissions(int $roleId): array
    {
        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->where('permissions.module', 'panels')
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }
}
