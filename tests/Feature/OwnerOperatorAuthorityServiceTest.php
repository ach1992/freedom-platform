<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\OwnerTransferService;
use App\Modules\Operations\Application\OwnerOperatorAuthorityService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class OwnerOperatorAuthorityServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456:abcdefghijklmnopqrstuvwxyzABCDE';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        config()->set('telegram.bot_token', self::BOT_TOKEN);
        config()->set('app.locale', 'fa');
    }

    public function test_installer_bootstrap_creates_exactly_one_owner_and_is_replay_safe(): void
    {
        $service = $this->app->make(OwnerOperatorAuthorityService::class);

        $created = $service->bootstrap(self::BOT_TOKEN, 700000001, 'fa');
        $replayed = $service->bootstrap(self::BOT_TOKEN, 700000001, 'fa');

        self::assertTrue($created['changed']);
        self::assertFalse($replayed['changed']);
        self::assertSame($created['administrator_id'], $replayed['administrator_id']);
        self::assertSame(1, DB::table('administrators')->where('is_owner', true)->count());
        self::assertSame(
            $created['user_id'],
            (int) DB::table('telegram_accounts')
                ->where('bot_id', '123456')
                ->where('telegram_user_id', 700000001)
                ->value('user_id'),
        );
        self::assertTrue(DB::table('customer_profiles')->where('user_id', $created['user_id'])->exists());
        self::assertSame(
            1,
            DB::table('audit_logs')->where('action', 'access.owner.bootstrap')->count(),
        );
    }

    public function test_installer_bootstrap_rejects_a_different_owner_without_leaking_partial_identity_state(): void
    {
        $service = $this->app->make(OwnerOperatorAuthorityService::class);
        $service->bootstrap(self::BOT_TOKEN, 700000010, 'fa');
        $usersBefore = DB::table('users')->count();

        try {
            $service->bootstrap(self::BOT_TOKEN, 700000011, 'fa');
            self::fail('A different installer Owner must be rejected after Owner authority exists.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Installer Owner conflicts with the existing database Owner.',
                $exception->getMessage(),
            );
        }

        self::assertSame($usersBefore, DB::table('users')->count());
        self::assertFalse(
            DB::table('telegram_accounts')->where('telegram_user_id', 700000011)->exists(),
        );
        self::assertSame(1, DB::table('administrators')->where('is_owner', true)->count());
    }

    public function test_local_recovery_requires_expected_current_owner_and_cancels_stale_transfer_intents(): void
    {
        $service = $this->app->make(OwnerOperatorAuthorityService::class);
        $bootstrap = $service->bootstrap(self::BOT_TOKEN, 700000020, 'fa');
        $ownerId = $bootstrap['administrator_id'];

        DB::table('administrators')->where('id', $ownerId)->update([
            'last_authenticated_at' => now('UTC'),
        ]);

        $pendingTargetId = $this->administrator();
        $transfer = $this->app->make(OwnerTransferService::class)->request(
            $pendingTargetId,
            300,
            new AccessChangeContext(
                'owner-recovery-test-request-0001',
                'owner-recovery-test-correlation-0001',
                'owner_recovery_test',
                'Create a pending transfer before local recovery.',
                $ownerId,
            ),
        );

        try {
            $service->recover(
                self::BOT_TOKEN,
                700000021,
                700000099,
                'Recover Owner after controlled operator verification.',
                'fa',
            );
            self::fail('Stale expected-current evidence must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Expected current Owner Telegram ID does not match authoritative state.',
                $exception->getMessage(),
            );
        }

        $recovered = $service->recover(
            self::BOT_TOKEN,
            700000021,
            700000020,
            'Recover Owner after controlled operator verification.',
            'fa',
        );

        self::assertTrue($recovered['changed']);
        self::assertSame($ownerId, $recovered['previous_administrator_id']);
        self::assertSame(1, $recovered['cancelled_transfer_count']);
        self::assertFalse((bool) DB::table('administrators')->where('id', $ownerId)->value('is_owner'));
        self::assertTrue((bool) DB::table('administrators')->where('id', $recovered['administrator_id'])->value('is_owner'));
        self::assertSame(1, DB::table('administrators')->where('is_owner', true)->count());
        self::assertSame(
            'cancelled',
            DB::table('owner_transfer_requests')
                ->where('id', $transfer->after['transfer_id'])
                ->value('state'),
        );
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.owner.recover')->count());
    }

    public function test_local_recovery_command_is_the_supported_post_install_mutation_path(): void
    {
        $service = $this->app->make(OwnerOperatorAuthorityService::class);
        $service->bootstrap(self::BOT_TOKEN, 700000030, 'fa');

        $missingExpected = Artisan::call('access:owner:recover', [
            'telegram_user_id' => '700000031',
            '--reason' => 'Operator recovery test.',
            '--yes' => true,
        ]);
        self::assertSame(2, $missingExpected);

        $exit = Artisan::call('access:owner:recover', [
            'telegram_user_id' => '700000031',
            '--expected-current' => '700000030',
            '--reason' => 'Operator recovery test.',
            '--yes' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertSame(700000031, $service->currentTelegramUserId(self::BOT_TOKEN));
        self::assertSame(1, DB::table('administrators')->where('is_owner', true)->count());
    }

    private function administrator(): int
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'suspended_at' => null,
            'revoked_at' => null,
            'status_reason_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
