<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorAccessService;
use App\Modules\AccessControl\Application\SensitiveActionApprovalService;
use App\Modules\AccessControl\Domain\PermissionEffect;
use App\Modules\AccessControl\Domain\SensitiveApprovalState;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ACL-003 SEC-002 QUA-001 */
final class SensitiveActionApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_independent_approval_is_bound_and_consumed_exactly_once(): void
    {
        $ownerId = $this->administrator(true);
        $requesterId = $this->administrator();
        $this->grantSalesRole($ownerId, $requesterId, 'grant-sales-sensitive-0001');
        $service = $this->app->make(SensitiveActionApprovalService::class);

        $request = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '42',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0001'),
        );
        $replayedRequest = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '42',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0001'),
        );
        $approved = $service->approve(
            $request->approvalId,
            $this->context($ownerId, 'sensitive-approve-0001'),
        );
        $consumeContext = $this->context($requesterId, 'sensitive-consume-0001');
        $consumed = $service->consume(
            $request->approvalId,
            'customer.tier.set',
            'user',
            '42',
            $consumeContext,
        );
        $replayedConsume = $service->consume(
            $request->approvalId,
            'customer.tier.set',
            'user',
            '42',
            $consumeContext,
        );

        self::assertSame(SensitiveApprovalState::Pending, $request->state);
        self::assertTrue($replayedRequest->replayed);
        self::assertSame(SensitiveApprovalState::Approved, $approved->state);
        self::assertTrue($consumed->consumed);
        self::assertTrue($replayedConsume->replayed);
        self::assertSame(1, DB::table('sensitive_action_approvals')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.sensitive.request')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.sensitive.approve')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.sensitive.consume')->count());
        self::assertSame($requesterId, (int) DB::table('sensitive_action_approvals')->value('consumed_by_administrator_id'));
    }

    public function test_requester_cannot_self_approve_an_independent_request(): void
    {
        $ownerId = $this->administrator(true);
        $requesterId = $this->administrator();
        $this->grantSalesRole($ownerId, $requesterId, 'grant-sales-sensitive-0010');
        $access = $this->app->make(AdministratorAccessService::class);
        $access->setPermissionOverride(
            $requesterId,
            'access.sensitive_actions.approve',
            PermissionEffect::Allow,
            $this->context($ownerId, 'grant-approval-sensitive-0010'),
        );
        $service = $this->app->make(SensitiveActionApprovalService::class);
        $request = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '43',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0010'),
        );

        try {
            $service->approve(
                $request->approvalId,
                $this->context($requesterId, 'sensitive-approve-0010'),
            );
            self::fail('Expected independent self-approval denial.');
        } catch (AuthorizationException) {
            self::assertSame('pending', DB::table('sensitive_action_approvals')->where('id', $request->approvalId)->value('state'));
            self::assertNull(DB::table('sensitive_action_approvals')->where('id', $request->approvalId)->value('decided_at'));
            self::assertSame(0, DB::table('audit_logs')->where('action', 'access.sensitive.approve')->count());
        }
    }

    public function test_current_requester_permission_is_required_at_consumption(): void
    {
        $ownerId = $this->administrator(true);
        $requesterId = $this->administrator();
        $this->grantSalesRole($ownerId, $requesterId, 'grant-sales-sensitive-0020');
        $service = $this->app->make(SensitiveActionApprovalService::class);
        $request = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '44',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0020'),
        );
        $service->approve(
            $request->approvalId,
            $this->context($ownerId, 'sensitive-approve-0020'),
        );
        $this->app->make(AdministratorAccessService::class)->revokeRole(
            $requesterId,
            'sales_content',
            $this->context($ownerId, 'revoke-sales-sensitive-0020'),
        );

        try {
            $service->consume(
                $request->approvalId,
                'customer.tier.set',
                'user',
                '44',
                $this->context($requesterId, 'sensitive-consume-0020'),
            );
            self::fail('Expected current requester authorization failure.');
        } catch (AuthorizationException) {
            self::assertNull(DB::table('sensitive_action_approvals')->where('id', $request->approvalId)->value('consumed_at'));
            self::assertSame(0, DB::table('audit_logs')->where('action', 'access.sensitive.consume')->count());
        }
    }

    public function test_approver_is_reauthorized_at_decision_time(): void
    {
        $ownerId = $this->administrator(true);
        $requesterId = $this->administrator();
        $approverId = $this->administrator();
        $this->grantSalesRole($ownerId, $requesterId, 'grant-sales-sensitive-0030');
        $access = $this->app->make(AdministratorAccessService::class);
        $access->setPermissionOverride(
            $approverId,
            'access.sensitive_actions.approve',
            PermissionEffect::Allow,
            $this->context($ownerId, 'grant-approval-sensitive-0030'),
        );
        $service = $this->app->make(SensitiveActionApprovalService::class);
        $request = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '45',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0030'),
        );
        $access->setPermissionOverride(
            $approverId,
            'access.sensitive_actions.approve',
            PermissionEffect::Inherit,
            $this->context($ownerId, 'revoke-approval-sensitive-0030'),
        );

        try {
            $service->approve(
                $request->approvalId,
                $this->context($approverId, 'sensitive-approve-0030'),
            );
            self::fail('Expected current approver authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame('pending', DB::table('sensitive_action_approvals')->where('id', $request->approvalId)->value('state'));
        }
    }

    public function test_rejection_expiration_cancellation_and_wrong_binding_fail_closed(): void
    {
        $ownerId = $this->administrator(true);
        $requesterId = $this->administrator();
        $this->grantSalesRole($ownerId, $requesterId, 'grant-sales-sensitive-0040');
        $service = $this->app->make(SensitiveActionApprovalService::class);

        $rejected = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '46',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0040'),
        );
        self::assertSame(
            SensitiveApprovalState::Rejected,
            $service->reject(
                $rejected->approvalId,
                $this->context($ownerId, 'sensitive-reject-0040'),
            )->state,
        );

        $expired = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '47',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0041'),
        );
        DB::table('sensitive_action_approvals')->where('id', $expired->approvalId)->update([
            'expires_at' => now('UTC')->subMinute(),
        ]);
        self::assertSame(
            SensitiveApprovalState::Expired,
            $service->approve(
                $expired->approvalId,
                $this->context($ownerId, 'sensitive-approve-0041'),
            )->state,
        );

        $cancelled = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '48',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0042'),
        );
        self::assertSame(
            SensitiveApprovalState::Cancelled,
            $service->cancel(
                $cancelled->approvalId,
                $this->context($requesterId, 'sensitive-cancel-0042'),
            )->state,
        );

        $bound = $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '49',
            true,
            300,
            $this->context($requesterId, 'sensitive-request-0043'),
        );
        $service->approve(
            $bound->approvalId,
            $this->context($ownerId, 'sensitive-approve-0043'),
        );

        try {
            $service->consume(
                $bound->approvalId,
                'customer.tier.set',
                'user',
                'different-target',
                $this->context($requesterId, 'sensitive-consume-0043'),
            );
            self::fail('Expected sensitive approval binding failure.');
        } catch (RuntimeException) {
            self::assertNull(DB::table('sensitive_action_approvals')->where('id', $bound->approvalId)->value('consumed_at'));
        }
    }

    public function test_request_fingerprint_conflicts_do_not_rebind_an_existing_approval(): void
    {
        $ownerId = $this->administrator(true);
        $requesterId = $this->administrator();
        $this->grantSalesRole($ownerId, $requesterId, 'grant-sales-sensitive-0050');
        $service = $this->app->make(SensitiveActionApprovalService::class);
        $context = $this->context($requesterId, 'sensitive-request-0050');

        $service->request(
            'identity.customers.manage_tier',
            'customer.tier.set',
            'user',
            '50',
            true,
            300,
            $context,
        );

        try {
            $service->request(
                'identity.customers.manage_tier',
                'customer.tier.set',
                'user',
                '51',
                true,
                300,
                $context,
            );
            self::fail('Expected sensitive approval fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Sensitive approval fingerprint conflict.', $exception->getMessage());
            self::assertSame('50', DB::table('sensitive_action_approvals')->value('target_id'));
            self::assertSame(1, DB::table('sensitive_action_approvals')->count());
        }
    }

    private function grantSalesRole(int $ownerId, int $administratorId, string $key): void
    {
        $this->app->make(AdministratorAccessService::class)->grantRole(
            $administratorId,
            'sales_content',
            $this->context($ownerId, $key),
        );
    }

    private function administrator(bool $owner = false): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user(),
            'status' => 'active',
            'is_owner' => $owner,
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

    private function context(int $actorAdministratorId, string $key): AccessChangeContext
    {
        return new AccessChangeContext(
            hash('sha256', $key),
            substr(hash('sha256', 'correlation:'.$key), 0, 64),
            'sensitive_action_test',
            'Sensitive action approval test.',
            $actorAdministratorId,
        );
    }
}
