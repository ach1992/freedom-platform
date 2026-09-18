<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
use App\Modules\Telegram\Application\TelegramAdministratorCustomerTargetSearchDisposition;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement COM-001 ADM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-004 */
final class TelegramAdministratorCustomerTargetDiscoveryTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_authorized_exact_search_is_bot_scoped_minimal_and_resolvable(): void
    {
        $actor = $this->administrator('support');
        $target = $this->customer(910001, 'Target_User', '123456789');
        $discovery = $this->app->make(TelegramAdministratorCustomerTargetDiscovery::class);

        self::assertTrue($discovery->availableFor($actor));

        foreach ([
            '910001',
            '@target_user',
            strtolower($target['public_id']),
        ] as $query) {
            $result = $discovery->search($actor, '123456789', $query);
            self::assertSame(TelegramAdministratorCustomerTargetSearchDisposition::Matched, $result->disposition);
            self::assertNotNull($result->target);
            self::assertSame('910001', $result->target->telegramUserId);
            self::assertSame($target['public_id'], $result->target->accountPublicId);
            self::assertSame('customer', $result->target->accountType);
            self::assertSame('active', $result->target->accountStatus);
            self::assertSame('Ta*******er', $result->target->maskedUsername);
            self::assertSame('fa', $result->target->locale);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $result->target->selectionToken);

            self::assertSame([
                'selectionToken',
                'telegramUserId',
                'accountPublicId',
                'accountType',
                'accountStatus',
                'maskedUsername',
                'locale',
            ], array_keys(get_object_vars($result->target)));

            $resolved = $discovery->resolve($actor, '123456789', $result->target->selectionToken);
            self::assertEquals($result->target, $resolved);
        }

        $otherBot = $discovery->search($actor, '987654321', '910001');
        self::assertSame(TelegramAdministratorCustomerTargetSearchDisposition::NotFound, $otherBot->disposition);
        self::assertNull($otherBot->target);
    }

    public function test_permission_bot_actor_and_current_target_state_are_rechecked_on_resolution(): void
    {
        $actor = $this->administrator('support');
        $secondActor = $this->administrator('support');
        $target = $this->customer(910002, 'resolve_target', '123456789');
        $discovery = $this->app->make(TelegramAdministratorCustomerTargetDiscovery::class);

        $result = $discovery->search($actor, '123456789', $target['public_id']);
        self::assertNotNull($result->target);
        $selection = $result->target->selectionToken;

        foreach ([
            fn () => $discovery->resolve($secondActor, '123456789', $selection),
            fn () => $discovery->resolve($actor, '987654321', $selection),
            fn () => $discovery->resolve($actor, '123456789', str_repeat('f', 40)),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Cross-actor, cross-bot, or forged customer selection must fail closed.');
            } catch (AuthorizationException) {
                // Expected.
            }
        }

        DB::table('administrator_role_assignments')
            ->where('administrator_id', $this->administratorIdForUser($actor))
            ->update(['revoked_at' => now('UTC'), 'updated_at' => now('UTC')]);

        try {
            $discovery->resolve($actor, '123456789', $selection);
            self::fail('Revoked customer-view permission must fail target re-resolution.');
        } catch (AuthorizationException) {
            // Expected.
        }

        DB::table('administrator_role_assignments')
            ->where('administrator_id', $this->administratorIdForUser($actor))
            ->update(['revoked_at' => null, 'updated_at' => now('UTC')]);

        DB::table('users')->where('id', $target['user_id'])->update([
            'account_status' => 'deleted',
            'updated_at' => now('UTC'),
        ]);

        try {
            $discovery->resolve($actor, '123456789', $selection);
            self::fail('Deleted customer target must fail current-state re-resolution.');
        } catch (AuthorizationException) {
            // Expected.
        }
    }

    public function test_unauthorized_invalid_no_match_and_ambiguous_search_reveal_no_target(): void
    {
        $unauthorized = $this->administrator();
        $authorized = $this->administrator('support');
        $this->customer(910003, 'duplicate_name', '123456789');
        $this->customer(910004, 'duplicate_name', '123456789');
        $discovery = $this->app->make(TelegramAdministratorCustomerTargetDiscovery::class);

        self::assertFalse($discovery->availableFor($unauthorized));
        try {
            $discovery->search($unauthorized, '123456789', '910003');
            self::fail('Unauthorized administrator customer search must fail closed.');
        } catch (AuthorizationException) {
            // Expected.
        }

        foreach (['', 'abcd', 'not-valid!', str_repeat('x', 129)] as $query) {
            $result = $discovery->search($authorized, '123456789', $query);
            self::assertSame(TelegramAdministratorCustomerTargetSearchDisposition::NotFound, $result->disposition);
            self::assertNull($result->target);
        }

        $ambiguous = $discovery->search($authorized, '123456789', '@duplicate_name');
        self::assertSame(TelegramAdministratorCustomerTargetSearchDisposition::Ambiguous, $ambiguous->disposition);
        self::assertNull($ambiguous->target);
    }

    /** @return array{user_id:int,public_id:string} */
    private function customer(int $telegramUserId, string $username, string $botId): array
    {
        $now = now('UTC');
        $publicId = (string) Str::ulid();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => (int) $botId,
            'telegram_user_id' => $telegramUserId,
            'username' => $username,
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['user_id' => $userId, 'public_id' => $publicId];
    }

    private function administrator(?string $roleCode = null, ?int $userId = null): int
    {
        $now = now('UTC');
        $userId ??= (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($roleCode !== null) {
            $roleId = DB::table('roles')->where('code', $roleCode)->where('is_active', true)->value('id');
            self::assertIsNumeric($roleId);
            DB::table('administrator_role_assignments')->insert([
                'administrator_id' => $administratorId,
                'role_id' => (int) $roleId,
                'granted_by_administrator_id' => null,
                'granted_at' => $now,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $userId;
    }

    private function administratorIdForUser(int $userId): int
    {
        $administratorId = DB::table('administrators')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->value('id');
        self::assertIsNumeric($administratorId);

        return (int) $administratorId;
    }
}
