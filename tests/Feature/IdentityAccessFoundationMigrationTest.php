<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement ONB-001 ONB-004 ONB-005 USR-001 USR-002 USR-003 AGT-001 AGT-002 ACL-001 ACL-002 ACL-003 SEC-002 SEC-003 */
final class IdentityAccessFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_customer_agent_and_access_tables_exist_with_idempotent_seed_data(): void
    {
        foreach ([
            'telegram_accounts',
            'customer_tiers',
            'customer_profiles',
            'customer_status_histories',
            'customer_tier_histories',
            'customer_tags',
            'customer_tag_assignments',
            'phone_numbers',
            'otp_challenges',
            'identity_items',
            'identity_item_histories',
            'administrators',
            'roles',
            'permissions',
            'role_permissions',
            'administrator_role_assignments',
            'administrator_permission_overrides',
            'sensitive_action_approvals',
            'owner_transfer_requests',
            'agent_applications',
            'agent_application_histories',
            'agent_profiles',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' must exist.');
        }

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(IdentityAccessFoundationSeeder::class);

        $this->assertSame(4, DB::table('customer_tiers')->count());
        $this->assertSame(4, DB::table('roles')->count());
        $this->assertSame(12, DB::table('permissions')->count());
        $this->assertDatabaseHas('customer_tiers', ['code' => 'vip', 'is_active' => true]);
        $this->assertDatabaseHas('permissions', [
            'code' => 'access.permissions.override',
            'risk_level' => 'critical',
            'requires_approval' => true,
        ]);
        $this->assertDatabaseHas('permissions', [
            'code' => 'admins.transfer_ownership',
            'risk_level' => 'critical',
            'requires_approval' => true,
        ]);
        $this->assertDatabaseHas('permissions', [
            'code' => 'identity.verified_data.view',
            'risk_level' => 'critical',
            'requires_approval' => true,
        ]);
        $this->assertDatabaseHas('permissions', [
            'code' => 'identity.verifications.manage',
            'risk_level' => 'high',
            'requires_approval' => true,
        ]);
    }

    public function test_telegram_identity_is_unique_per_bot_and_one_account_maps_once_per_bot(): void
    {
        $firstUser = $this->user();
        $secondUser = $this->user();
        $now = now('UTC');

        DB::table('telegram_accounts')->insert([
            'user_id' => $firstUser,
            'bot_id' => 100,
            'telegram_user_id' => 200,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(QueryException::class);

        DB::table('telegram_accounts')->insert([
            'user_id' => $secondUser,
            'bot_id' => 100,
            'telegram_user_id' => 200,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_active_phone_hash_is_globally_unique_but_released_history_remains_reusable(): void
    {
        $firstUser = $this->user();
        $secondUser = $this->user();
        $hash = hash('sha256', 'test-only-normalized-phone');
        $now = now('UTC');

        DB::table('phone_numbers')->insert([
            'user_id' => $firstUser,
            'encrypted_value' => 'test-only-encrypted-value-one',
            'lookup_hash' => $hash,
            'active_lookup_hash' => $hash,
            'hash_key_version' => 1,
            'status' => 'verified',
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('phone_numbers')->where('user_id', $firstUser)->update([
            'active_lookup_hash' => null,
            'status' => 'released',
            'released_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('phone_numbers')->insert([
            'user_id' => $secondUser,
            'encrypted_value' => 'test-only-encrypted-value-two',
            'lookup_hash' => $hash,
            'active_lookup_hash' => $hash,
            'hash_key_version' => 1,
            'status' => 'verified',
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(2, DB::table('phone_numbers')->where('lookup_hash', $hash)->count());
        $this->assertSame(1, DB::table('phone_numbers')->where('active_lookup_hash', $hash)->count());
    }

    public function test_database_allows_only_one_active_agent_application_per_customer(): void
    {
        $customer = $this->user();
        $now = now('UTC');

        DB::table('agent_applications')->insert([
            'customer_id' => $customer,
            'active_customer_id' => $customer,
            'state' => 'submitted',
            'application_version' => 1,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('agent_applications')->insert([
                'customer_id' => $customer,
                'active_customer_id' => $customer,
                'state' => 'submitted',
                'application_version' => 2,
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('A second active agent application must violate the database constraint.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('agent_applications')->where('active_customer_id', $customer)->count());
        }

        DB::table('agent_applications')->where('active_customer_id', $customer)->update([
            'active_customer_id' => null,
            'state' => 'rejected',
            'decided_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('agent_applications')->insert([
            'customer_id' => $customer,
            'active_customer_id' => $customer,
            'state' => 'submitted',
            'application_version' => 2,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(2, DB::table('agent_applications')->where('customer_id', $customer)->count());
        $this->assertSame(1, DB::table('agent_applications')->where('active_customer_id', $customer)->count());
    }

    public function test_permission_override_and_sensitive_request_fingerprint_are_unique(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        $administrator = $this->administrator();
        $permission = (int) DB::table('permissions')->where('code', 'access.permissions.override')->value('id');
        $now = now('UTC');

        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administrator,
            'permission_id' => $permission,
            'effect' => 'deny',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('administrator_permission_overrides')->insert([
                'administrator_id' => $administrator,
                'permission_id' => $permission,
                'effect' => 'allow',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('Only one override row is allowed per administrator and permission.');
        } catch (QueryException) {
            $this->assertDatabaseHas('administrator_permission_overrides', [
                'administrator_id' => $administrator,
                'permission_id' => $permission,
                'effect' => 'deny',
            ]);
        }

        $fingerprint = hash('sha256', 'test-only-sensitive-request');
        DB::table('sensitive_action_approvals')->insert([
            'id' => '01K1Y7Y2H9H0FY7PWH3Q4JY4AA',
            'requested_by_administrator_id' => $administrator,
            'action' => 'access.permissions.override',
            'request_fingerprint' => $fingerprint,
            'state' => 'pending',
            'expires_at' => $now->copy()->addMinutes(10),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(QueryException::class);

        DB::table('sensitive_action_approvals')->insert([
            'id' => '01K1Y7Y2H9H0FY7PWH3Q4JY4AB',
            'requested_by_administrator_id' => $administrator,
            'action' => 'access.permissions.override',
            'request_fingerprint' => $fingerprint,
            'state' => 'pending',
            'expires_at' => $now->copy()->addMinutes(10),
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

    private function administrator(): int
    {
        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user(),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
