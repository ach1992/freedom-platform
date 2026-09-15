<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Payments\Usdt\Application\UsdtDestinationWalletService;
use App\Shared\Application\Clock;
use Database\Seeders\DatabaseSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final readonly class StandardSeedUsdtClock implements Clock
{
    public function __construct(private DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement USDT-001 ACL-001 ACL-002 SEC-002 QUA-001 */
final class UsdtStandardSeedAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_standard_seed_provisions_finance_usdt_management_for_non_owner_and_denies_ungranted_admin(): void
    {
        $permission = DB::table('permissions')
            ->where('code', 'payments.usdt.manage')
            ->first(['id', 'module', 'risk_level']);
        self::assertNotNull($permission);
        self::assertSame('payments', $permission->module);
        self::assertSame('high', $permission->risk_level);

        $financeRole = DB::table('roles')
            ->where('code', 'finance')
            ->where('is_active', true)
            ->first(['id']);
        self::assertNotNull($financeRole);
        self::assertTrue(DB::table('role_permissions')
            ->where('role_id', (int) $financeRole->id)
            ->where('permission_id', (int) $permission->id)
            ->exists());

        $financeAdministrator = $this->administrator();
        $this->assignRole($financeAdministrator, (int) $financeRole->id);
        $ungrantedAdministrator = $this->administrator();
        $service = new UsdtDestinationWalletService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(AdministratorPermissionAuthorizer::class),
            new StandardSeedUsdtClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00')),
        );
        $financeAddress = '0x'.str_repeat('77', 20);

        $configured = $service->configure(
            'usdt.wallet.standard-seed.0001',
            $financeAdministrator,
            'primary',
            $financeAddress,
            true,
            'Standard-seed finance authorization regression.',
            hash('sha256', 'usdt-standard-seed-finance'),
        );
        self::assertSame(1, $configured->version);
        self::assertSame('BEP20', $configured->network);
        self::assertSame($financeAddress, $configured->address);
        self::assertFalse($configured->replayed);

        $this->assertAuthorizationDenied(fn () => $service->configure(
            'usdt.wallet.standard-seed.denied.0001',
            $ungrantedAdministrator,
            'denied',
            '0x'.str_repeat('88', 20),
            true,
            'Ungrant regression.',
            hash('sha256', 'usdt-standard-seed-denied'),
        ));
        self::assertSame(1, DB::table('usdt_destination_wallet_versions')->count());
    }

    private function administrator(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user(),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assignRole(int $administratorId, int $roleId): void
    {
        $now = now('UTC');

        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization exception.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }
}
