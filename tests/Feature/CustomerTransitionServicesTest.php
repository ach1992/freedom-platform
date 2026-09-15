<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customers\Application\CustomerAccountStateService;
use App\Modules\Customers\Application\CustomerChangeContext;
use App\Modules\Customers\Application\CustomerTagService;
use App\Modules\Customers\Application\CustomerTierMetrics;
use App\Modules\Customers\Application\CustomerTierService;
use App\Modules\Customers\Domain\CustomerTierCode;
use App\Modules\Identity\Domain\AccountStatus;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ONB-005 USR-002 USR-003 SEC-002 QUA-001 */
final class CustomerTransitionServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_status_transitions_are_transactional_audited_and_replay_safe(): void
    {
        $userId = $this->customer();
        $administratorId = $this->administrator(permissions: ['identity.customers.manage_status']);
        $service = $this->app->make(CustomerAccountStateService::class);
        $suspend = $this->context('customer-status-0001', 'customer-status-correlation-0001', 'risk_review', $administratorId);
        $activate = $this->context('customer-status-0002', 'customer-status-correlation-0002', 'review_cleared', $administratorId);

        $first = $service->transition($userId, AccountStatus::Suspended, $suspend);
        $second = $service->transition($userId, AccountStatus::Active, $activate);
        $replay = $service->transition($userId, AccountStatus::Suspended, $suspend);

        self::assertTrue($first->changed);
        self::assertTrue($second->changed);
        self::assertTrue($replay->replayed);
        self::assertSame('suspended', $replay->after['account_status']);
        self::assertSame('active', DB::table('users')->where('id', $userId)->value('account_status'));
        self::assertSame(2, DB::table('customer_status_histories')->where('user_id', $userId)->count());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'customer.status.transition')->count());
    }

    public function test_inactive_administrator_cannot_change_customer_status_and_transaction_rolls_back(): void
    {
        $userId = $this->customer();
        $administratorId = $this->administrator('disabled');

        try {
            $this->app->make(CustomerAccountStateService::class)->transition(
                $userId,
                AccountStatus::Blocked,
                $this->context('customer-status-0003', 'customer-status-correlation-0003', 'manual_block', $administratorId),
            );
            self::fail('Expected inactive administrator rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame('An active administrator is required.', $exception->getMessage());
        }

        self::assertSame('active', DB::table('users')->where('id', $userId)->value('account_status'));
        self::assertSame(0, DB::table('customer_status_histories')->where('user_id', $userId)->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'customer.status.transition')->count());
    }

    public function test_active_administrator_without_permissions_cannot_mutate_customer_status_tier_or_tags(): void
    {
        $userId = $this->customer();
        $administratorId = $this->administrator();
        $now = now('UTC');
        DB::table('customer_tags')->insert([
            'code' => 'risk_review',
            'name_translation_key' => 'customer_tags.risk_review',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertAdministratorAuthorizationDenied(fn (): mixed => $this->app->make(CustomerAccountStateService::class)->transition(
            $userId,
            AccountStatus::Suspended,
            $this->context('customer-status-denied-0001', 'customer-status-denied-correlation-0001', 'risk_review', $administratorId),
        ));
        $this->assertAdministratorAuthorizationDenied(fn (): mixed => $this->app->make(CustomerTierService::class)->assignManual(
            $userId,
            CustomerTierCode::Loyal,
            true,
            $this->context('customer-tier-denied-0001', 'customer-tier-denied-correlation-0001', 'manual_override', $administratorId),
        ));
        $this->assertAdministratorAuthorizationDenied(fn (): mixed => $this->app->make(CustomerTagService::class)->assign(
            $userId,
            'risk_review',
            $this->context('customer-tag-assign-denied-0001', 'customer-tag-assign-denied-correlation-0001', 'manual_assignment', $administratorId),
        ));
        $this->assertAdministratorAuthorizationDenied(fn (): mixed => $this->app->make(CustomerTagService::class)->remove(
            $userId,
            'risk_review',
            $this->context('customer-tag-remove-denied-0001', 'customer-tag-remove-denied-correlation-0001', 'manual_assignment', $administratorId),
        ));

        self::assertSame('active', DB::table('users')->where('id', $userId)->value('account_status'));
        self::assertSame('new', $this->currentTierCode($userId));
        self::assertSame(0, DB::table('customer_status_histories')->where('user_id', $userId)->count());
        self::assertSame(0, DB::table('customer_tier_histories')->where('user_id', $userId)->count());
        self::assertSame(0, DB::table('customer_tag_assignments')->where('user_id', $userId)->count());
        self::assertSame(0, DB::table('audit_logs')->whereIn('action', [
            'customer.status.transition',
            'customer.tier.assign',
            'customer.tag.assign',
            'customer.tag.remove',
        ])->count());
    }

    public function test_manual_tier_lock_blocks_recalculation_then_unlock_allows_promotion_without_automatic_downgrade(): void
    {
        $userId = $this->customer();
        $administratorId = $this->administrator(permissions: ['identity.customers.manage_tier']);
        $service = $this->app->make(CustomerTierService::class);

        $service->assignManual(
            $userId,
            CustomerTierCode::Loyal,
            true,
            $this->context('customer-tier-manual-0001', 'customer-tier-correlation-0001', 'manual_override', $administratorId),
        );

        $locked = $service->recalculate(
            $userId,
            new CustomerTierMetrics(10, 90),
            $this->systemContext('customer-tier-auto-0001', 'customer-tier-auto-correlation-0001'),
        );

        self::assertFalse($locked->changed);
        self::assertSame('loyal', $this->currentTierCode($userId));

        $service->assignManual(
            $userId,
            CustomerTierCode::Loyal,
            false,
            $this->context('customer-tier-manual-0002', 'customer-tier-correlation-0002', 'unlock_override', $administratorId),
        );

        $promotion = $service->recalculate(
            $userId,
            new CustomerTierMetrics(10, 90),
            $this->systemContext('customer-tier-auto-0002', 'customer-tier-auto-correlation-0002'),
        );

        self::assertTrue($promotion->changed);
        self::assertSame('vip', $this->currentTierCode($userId));

        $downgrade = $service->recalculate(
            $userId,
            new CustomerTierMetrics(1, 1),
            $this->systemContext('customer-tier-auto-0003', 'customer-tier-auto-correlation-0003'),
        );

        self::assertFalse($downgrade->changed);
        self::assertSame('vip', $this->currentTierCode($userId));
        self::assertSame(2, DB::table('customer_tier_histories')->where('user_id', $userId)->count());
        self::assertSame(5, DB::table('audit_logs')->whereIn('action', [
            'customer.tier.assign',
            'customer.tier.recalculate',
        ])->count());
    }

    public function test_tag_assign_remove_and_reassign_keep_one_assignment_and_append_audit(): void
    {
        $userId = $this->customer();
        $administratorId = $this->administrator(permissions: ['identity.customers.manage_tags']);
        $now = now('UTC');
        DB::table('customer_tags')->insert([
            'code' => 'risk_review',
            'name_translation_key' => 'customer_tags.risk_review',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $service = $this->app->make(CustomerTagService::class);
        $assign = $this->context('customer-tag-assign-0001', 'customer-tag-correlation-0001', 'manual_assignment', $administratorId);

        $service->assign($userId, 'risk_review', $assign);
        $service->remove(
            $userId,
            'risk_review',
            $this->context('customer-tag-remove-0001', 'customer-tag-correlation-0002', 'review_completed', $administratorId),
        );
        $replay = $service->assign($userId, 'risk_review', $assign);

        self::assertTrue($replay->replayed);
        self::assertNotNull(DB::table('customer_tag_assignments')->where('user_id', $userId)->value('removed_at'));

        $service->assign(
            $userId,
            'risk_review',
            $this->context('customer-tag-assign-0002', 'customer-tag-correlation-0003', 'review_reopened', $administratorId),
        );

        self::assertSame(1, DB::table('customer_tag_assignments')->where('user_id', $userId)->count());
        self::assertNull(DB::table('customer_tag_assignments')->where('user_id', $userId)->value('removed_at'));
        self::assertSame(3, DB::table('audit_logs')->whereIn('action', [
            'customer.tag.assign',
            'customer.tag.remove',
        ])->count());
    }

    public function test_audit_request_fingerprint_is_unique_per_action(): void
    {
        $now = now('UTC');
        $entry = [
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'customer.test.mutation',
            'target_type' => 'user',
            'target_id' => '1',
            'before_safe_data' => '{}',
            'after_safe_data' => '{}',
            'reason_code' => 'test',
            'reason' => null,
            'correlation_id' => 'customer-test-correlation-0001',
            'request_fingerprint' => 'customer-test-request-0001',
            'created_at' => $now,
        ];
        DB::table('audit_logs')->insert($entry);

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->insert($entry);
    }

    private function customer(): int
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
        $newTierId = (int) DB::table('customer_tiers')->where('code', 'new')->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => $newTierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => 'unverified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    /** @param list<string> $permissions */
    private function administrator(string $status = 'active', array $permissions = []): int
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

        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => $status,
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($permissions as $permissionCode) {
            $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id');

            if (! is_int($permissionId) && ! is_string($permissionId)) {
                throw new RuntimeException('Test permission does not exist.');
            }

            DB::table('administrator_permission_overrides')->insert([
                'administrator_id' => $administratorId,
                'permission_id' => (int) $permissionId,
                'effect' => 'allow',
                'changed_by_administrator_id' => $administratorId,
                'reason_code' => 'test_permission_grant',
                'reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $administratorId;
    }

    private function assertAdministratorAuthorizationDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected administrator authorization rejection.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Administrator authorization failed.', $exception->getMessage());
        }
    }

    private function context(
        string $fingerprint,
        string $correlationId,
        string $reasonCode,
        int $administratorId,
    ): CustomerChangeContext {
        return new CustomerChangeContext(
            $fingerprint,
            $correlationId,
            $reasonCode,
            actorAdministratorId: $administratorId,
        );
    }

    private function systemContext(string $fingerprint, string $correlationId): CustomerChangeContext
    {
        return new CustomerChangeContext(
            $fingerprint,
            $correlationId,
            'automatic_recalculation',
        );
    }

    private function currentTierCode(int $userId): string
    {
        return (string) DB::table('customer_profiles')
            ->join('customer_tiers', 'customer_tiers.id', '=', 'customer_profiles.current_tier_id')
            ->where('customer_profiles.user_id', $userId)
            ->value('customer_tiers.code');
    }
}
