<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorSearchPermissions;
use Database\Seeders\AdministratorSearchAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement ADM-001 ACL-001 ACL-002 SEC-002 QUA-001 */
final class AdministratorSearchAccessFoundationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_permissions_and_role_grants_are_exact_and_idempotent(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(AdministratorSearchAccessFoundationSeeder::class);
        $this->seed(AdministratorSearchAccessFoundationSeeder::class);

        $permissions = DB::table('permissions')
            ->where('module', 'administration')
            ->orderBy('code')
            ->get(['code', 'risk_level', 'requires_approval']);

        self::assertSame([
            AdministratorSearchPermissions::ORDER,
            AdministratorSearchPermissions::PAYMENT_EVIDENCE,
            AdministratorSearchPermissions::PAYMENT,
            AdministratorSearchPermissions::SERVICE,
        ], $permissions->pluck('code')->all());

        self::assertSame([
            AdministratorSearchPermissions::ORDER,
            AdministratorSearchPermissions::PAYMENT_EVIDENCE,
            AdministratorSearchPermissions::PAYMENT,
        ], $this->rolePermissions('finance'));

        self::assertSame([
            AdministratorSearchPermissions::ORDER,
            AdministratorSearchPermissions::PAYMENT,
            AdministratorSearchPermissions::SERVICE,
        ], $this->rolePermissions('support'));

        self::assertSame([
            AdministratorSearchPermissions::ORDER,
            AdministratorSearchPermissions::SERVICE,
        ], $this->rolePermissions('technical'));

        self::assertSame([
            AdministratorSearchPermissions::ORDER,
        ], $this->rolePermissions('sales_content'));

        self::assertSame('high', DB::table('permissions')
            ->where('code', AdministratorSearchPermissions::PAYMENT_EVIDENCE)
            ->value('risk_level'));
        self::assertSame(0, (int) DB::table('permissions')
            ->where('code', AdministratorSearchPermissions::PAYMENT_EVIDENCE)
            ->value('requires_approval'));
    }

    /** @return list<string> */
    private function rolePermissions(string $roleCode): array
    {
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');

        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->where('permissions.module', 'administration')
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->map(static fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }
}
