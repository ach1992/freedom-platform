<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement USR-001 USR-002 USR-003 SEC-003 */
final class CustomerAccountSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_self_summary_combines_commercial_identity_agent_and_admin_state_without_raw_secrets(): void
    {
        $userId = $this->user('agent', 'limited');
        $now = now('UTC');
        $tierId = (int) DB::table('customer_tiers')->where('code', 'loyal')->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => $tierId,
            'tier_locked' => true,
            'tier_lock_reason_code' => 'manual_review',
            'phone_verification_status' => 'verified',
            'identity_verification_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawPhone = '09121234567';
        $phoneHash = hash('sha256', 'summary-phone-hash');
        DB::table('phone_numbers')->insert([
            'user_id' => $userId,
            'encrypted_value' => 'encrypted:'.$rawPhone,
            'lookup_hash' => $phoneHash,
            'active_lookup_hash' => $phoneHash,
            'hash_key_version' => 1,
            'status' => 'verified',
            'last_verification_method' => 'sms_otp',
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $nationalId = '1234567891';
        DB::table('identity_items')->insert([
            'user_id' => $userId,
            'type' => 'national_id',
            'encrypted_value' => 'encrypted:'.$nationalId,
            'lookup_hash' => hash('sha256', 'summary-national-hash'),
            'active_lookup_hash' => hash('sha256', 'summary-national-active-hash'),
            'hash_key_version' => 1,
            'masked_value' => '******7891',
            'state' => 'verified',
            'ownership_check_required' => false,
            'ownership_check_status' => 'not_required',
            'version' => 2,
            'submitted_at' => $now,
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'trusted',
            'name_translation_key' => 'customer_tags.trusted',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => $tagId,
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $applicationId = (int) DB::table('agent_applications')->insertGetId([
            'customer_id' => $userId,
            'active_customer_id' => null,
            'state' => 'approved',
            'application_version' => 1,
            'submitted_at' => $now,
            'decided_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('agent_profiles')->insert([
            'user_id' => $userId,
            'approved_application_id' => $applicationId,
            'status' => 'limited',
            'pricing_profile_code' => 'default',
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('administrators')->insert([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $summary = $this->app->make(CustomerAccountSummaryService::class)->forSelf($userId, $userId);
        $safe = $summary->toSafeArray();
        $encoded = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertSame('agent', $safe['account_type']);
        self::assertSame('limited', $safe['account_status']);
        self::assertSame('loyal', $safe['tier_code']);
        self::assertTrue($safe['tier_locked']);
        self::assertSame('verified', $safe['phone_verification_status']);
        self::assertSame('sms_otp', $safe['phone_verification_method']);
        self::assertSame('verified', $safe['identity_verification_status']);
        self::assertSame(['trusted'], $safe['tags']);
        self::assertSame('limited', $safe['agent_status']);
        self::assertSame('approved', $safe['agent_application_state']);
        self::assertSame('active', $safe['administrator_status']);
        self::assertTrue($safe['is_owner']);
        self::assertStringContainsString('******7891', $encoded);
        self::assertStringNotContainsString($rawPhone, $encoded);
        self::assertStringNotContainsString($nationalId, $encoded);
        self::assertStringNotContainsString($phoneHash, $encoded);
        self::assertStringNotContainsString('encrypted:', $encoded);
        self::assertArrayNotHasKey('lookup_hash', $safe);
        self::assertArrayNotHasKey('encrypted_value', $safe);
    }

    public function test_summary_uses_safe_defaults_when_optional_profiles_do_not_exist(): void
    {
        $userId = $this->user();

        $safe = $this->app->make(CustomerAccountSummaryService::class)
            ->forSelf($userId, $userId)
            ->toSafeArray();

        self::assertNull($safe['tier_code']);
        self::assertFalse($safe['tier_locked']);
        self::assertSame('unverified', $safe['phone_verification_status']);
        self::assertNull($safe['phone_verification_method']);
        self::assertSame('unverified', $safe['identity_verification_status']);
        self::assertSame([], $safe['identity_items']);
        self::assertSame([], $safe['tags']);
        self::assertNull($safe['agent_status']);
        self::assertNull($safe['administrator_status']);
        self::assertFalse($safe['is_owner']);
    }

    public function test_summary_cannot_be_read_for_another_user(): void
    {
        $userId = $this->user();
        $otherUserId = $this->user();

        $this->expectException(AuthorizationException::class);

        $this->app->make(CustomerAccountSummaryService::class)->forSelf($userId, $otherUserId);
    }

    public function test_deleted_account_summary_is_unavailable(): void
    {
        $userId = $this->user(accountStatus: 'deleted');

        $this->expectException(RuntimeException::class);

        $this->app->make(CustomerAccountSummaryService::class)->forSelf($userId, $userId);
    }

    private function user(string $accountType = 'customer', string $accountStatus = 'active'): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => $accountType,
            'account_status' => $accountStatus,
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
