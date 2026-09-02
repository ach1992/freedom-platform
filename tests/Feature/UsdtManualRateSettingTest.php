<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Payments\Usdt\Application\UsdtManualRateSettingService;
use App\Modules\Telegram\Application\Contracts\TelegramManagedUsdtRateSettings;
use App\Shared\Application\Clock;
use Database\Seeders\DatabaseSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final readonly class ManagedUsdtRateClock implements Clock
{
    public function __construct(private DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement USDT-002 IPG-002 ACL-001 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class UsdtManualRateSettingTest extends TestCase
{
    use RefreshDatabase;

    private ManagedUsdtRateClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->clock = new ManagedUsdtRateClock(new DateTimeImmutable('2026-08-14T06:15:00+00:00'));
        Config::set('usdt.rate.min_irr', '100000');
        Config::set('usdt.rate.max_irr', '10000000');
    }

    public function test_bootstrap_rate_is_used_only_until_managed_setting_exists(): void
    {
        Config::set('usdt.rate.manual_irr', '900000');
        $service = $this->service();

        $bootstrap = $service->current();
        self::assertNotNull($bootstrap);
        self::assertNull($bootstrap->version);
        self::assertSame('900000.00000000', $bootstrap->rateIrr);
        self::assertSame('bootstrap', $bootstrap->source);

        $finance = $this->financeAdministrator();
        $managed = $service->set($finance, '910000', 'usdt-rate-setting-0001', 'corr-usdt-rate-0001');
        self::assertGreaterThan(0, $managed->version ?? 0);
        self::assertSame('910000.00000000', $managed->rateIrr);
        self::assertSame('managed', $managed->source);
        self::assertFalse($managed->replayed);

        Config::set('usdt.rate.manual_irr', '920000');
        $current = $service->current();
        self::assertNotNull($current);
        self::assertSame($managed->version, $current->version);
        self::assertSame('910000.00000000', $current->rateIrr);
        self::assertSame('managed', $current->source);
    }

    public function test_updates_are_append_only_audited_and_request_replay_is_idempotent(): void
    {
        Config::set('usdt.rate.manual_irr', null);
        $service = $this->service();
        $finance = $this->financeAdministrator();

        $first = $service->set($finance, '900000', 'usdt-rate-setting-0002', 'corr-usdt-rate-0002');
        $replay = $service->set($finance, '900000.00000000', 'usdt-rate-setting-0002', 'corr-usdt-rate-0002-replay');
        $second = $service->set($finance, '905000', 'usdt-rate-setting-0003', 'corr-usdt-rate-0003');

        self::assertSame($first->version, $replay->version);
        self::assertTrue($replay->replayed);
        self::assertGreaterThan($first->version ?? 0, $second->version ?? 0);
        self::assertSame(2, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        self::assertSame('905000.00000000', $service->current()?->rateIrr);
    }

    public function test_ungranted_administrator_cannot_change_financial_rate(): void
    {
        Config::set('usdt.rate.manual_irr', null);
        $administrator = $this->administrator();

        try {
            $this->service()->set(
                $administrator,
                '900000',
                'usdt-rate-setting-denied',
                'corr-usdt-rate-denied',
            );
            self::fail('Expected authorization exception.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        }
    }

    public function test_telegram_managed_rate_bridge_reauthorizes_from_user_identity_and_reuses_canonical_idempotency(): void
    {
        Config::set('usdt.rate.manual_irr', '900000');
        $administratorId = $this->financeAdministrator();
        $userId = (int) DB::table('administrators')->where('id', $administratorId)->value('user_id');
        self::assertGreaterThan(0, $userId);

        $bridge = $this->app->make(TelegramManagedUsdtRateSettings::class);
        self::assertTrue($bridge->availableFor($userId));
        self::assertFalse((bool) DB::table('permissions')->where('code', 'payments.usdt.manage')->value('requires_approval'));
        self::assertSame('910000.00000000', $bridge->validateFor($userId, '910000'));
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());

        $bootstrap = $bridge->currentFor($userId);
        self::assertNotNull($bootstrap);
        self::assertNull($bootstrap->version);
        self::assertSame('900000.00000000', $bootstrap->rateIrr);
        self::assertSame('bootstrap', $bootstrap->source);

        $created = $bridge->setFor($userId, '910000', 'telegram-rate-bridge-0001', 'telegram-rate-corr-0001');
        $replayed = $bridge->setFor($userId, '910000.00000000', 'telegram-rate-bridge-0001', 'telegram-rate-corr-0001');
        self::assertNotNull($created->version);
        self::assertSame($created->version, $replayed->version);
        self::assertFalse($created->replayed);
        self::assertTrue($replayed->replayed);
        self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());

        DB::table('administrators')->where('id', $administratorId)->update([
            'status' => 'disabled',
            'updated_at' => now('UTC'),
        ]);
        self::assertFalse($bridge->availableFor($userId));
        try {
            $bridge->validateFor($userId, '920000');
            self::fail('Disabled administrator must not validate Telegram managed-rate candidates.');
        } catch (AuthorizationException) {
            self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        }
        try {
            $bridge->setFor($userId, '920000', 'telegram-rate-bridge-0002', 'telegram-rate-corr-0002');
            self::fail('Disabled administrator must not retain Telegram managed-rate authority.');
        } catch (AuthorizationException) {
            self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        }
    }

    public function test_telegram_managed_rate_bridge_accepts_active_owner_without_finance_role_and_denies_ungranted_admin(): void
    {
        Config::set('usdt.rate.manual_irr', '900000');
        $now = now('UTC');
        $ownerUserId = $this->user();
        DB::table('administrators')->insert([
            'user_id' => $ownerUserId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $bridge = $this->app->make(TelegramManagedUsdtRateSettings::class);
        self::assertTrue($bridge->availableFor($ownerUserId));
        self::assertSame('900000.00000000', $bridge->currentFor($ownerUserId)?->rateIrr);

        $ungrantedAdministratorId = $this->administrator();
        $ungrantedUserId = (int) DB::table('administrators')->where('id', $ungrantedAdministratorId)->value('user_id');
        self::assertFalse($bridge->availableFor($ungrantedUserId));
        $this->expectException(AuthorizationException::class);
        $bridge->currentFor($ungrantedUserId);
    }

    public function test_database_history_cannot_be_updated_or_deleted(): void
    {
        Config::set('usdt.rate.manual_irr', null);
        $finance = $this->financeAdministrator();
        $created = $this->service()->set($finance, '900000', 'usdt-rate-setting-0004', 'corr-usdt-rate-0004');
        self::assertNotNull($created->version);

        $this->assertQueryRejected(fn (): int => DB::table('usdt_manual_rate_versions')->where('id', $created->version)->update([
            'rate_irr' => '950000.00000000',
        ]));
        $this->assertQueryRejected(fn (): int => DB::table('usdt_manual_rate_versions')->where('id', $created->version)->delete());
        self::assertSame('900000.00000000', $this->service()->current()?->rateIrr);
    }

    public function test_invalid_manual_rate_format_is_a_domain_rejection_without_persistence(): void
    {
        $finance = $this->financeAdministrator();

        try {
            $this->service()->set($finance, '000900000', 'usdt-rate-setting-format', 'corr-usdt-rate-format');
            self::fail('Expected invalid manual rate format to be rejected.');
        } catch (\DomainException) {
            self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        }
    }

    public function test_invalid_configured_rate_bounds_remain_runtime_configuration_failures(): void
    {
        Config::set('usdt.rate.min_irr', 'invalid');
        Config::set('usdt.rate.max_irr', '10000000');
        $finance = $this->financeAdministrator();

        $this->expectException(\RuntimeException::class);
        $this->service()->set($finance, '900000', 'usdt-rate-setting-config', 'corr-usdt-rate-config');
    }

    public function test_invalid_bootstrap_rate_remains_a_runtime_configuration_failure(): void
    {
        Config::set('usdt.rate.manual_irr', 'invalid');

        $this->expectException(\RuntimeException::class);
        $this->service()->current();
    }

    public function test_manual_rate_outside_shared_sanity_bounds_is_rejected(): void
    {
        $finance = $this->financeAdministrator();
        $this->expectException(\DomainException::class);
        $this->service()->set($finance, '99999', 'usdt-rate-setting-low', 'corr-usdt-rate-low');
    }

    private function service(): UsdtManualRateSettingService
    {
        return new UsdtManualRateSettingService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(AdministratorPermissionAuthorizer::class),
            $this->app['config'],
            $this->clock,
        );
    }

    private function financeAdministrator(): int
    {
        $administrator = $this->administrator();
        $role = DB::table('roles')->where('code', 'finance')->where('is_active', true)->first(['id']);
        self::assertNotNull($role);
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administrator,
            'role_id' => (int) $role->id,
            'granted_by_administrator_id' => null,
            'granted_at' => now('UTC'),
            'revoked_at' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $administrator;
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

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
