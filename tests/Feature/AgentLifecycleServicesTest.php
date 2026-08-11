<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentApplicationService;
use App\Modules\Agents\Application\AgentChangeContext;
use App\Modules\Agents\Application\AgentProfileService;
use App\Modules\Agents\Domain\AgentStatus;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement AGT-001 AGT-002 ACL-001 ACL-002 SEC-002 QUA-001 */
final class AgentLifecycleServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_submission_claim_and_approval_are_transactional_and_replay_safe(): void
    {
        $customerId = $this->user();
        $ownerId = $this->administrator(true);
        $service = $this->app->make(AgentApplicationService::class);
        $submitContext = $this->customerContext($customerId, 'agent-submit-request-0001', 'agent-submit-correlation-0001');

        $first = $service->submit($customerId, $submitContext);
        $replay = $service->submit($customerId, $submitContext);
        $applicationId = (int) DB::table('agent_applications')->where('customer_id', $customerId)->value('id');
        $service->claim($applicationId, $this->adminContext($ownerId, 'agent-claim-request-0001', 'agent-claim-correlation-0001'));
        $approved = $service->approve(
            $applicationId,
            'standard',
            $this->adminContext($ownerId, 'agent-approve-request-0001', 'agent-approve-correlation-0001', 'approved', 'Commercial criteria passed.'),
        );

        self::assertTrue($first->changed);
        self::assertTrue($replay->replayed);
        self::assertTrue($approved->changed);
        self::assertSame('approved', DB::table('agent_applications')->where('id', $applicationId)->value('state'));
        self::assertSame('agent', DB::table('users')->where('id', $customerId)->value('account_type'));
        self::assertDatabaseHas('agent_profiles', [
            'user_id' => $customerId,
            'status' => 'active',
            'pricing_profile_code' => 'standard',
            'approved_application_id' => $applicationId,
        ]);
        self::assertSame(3, DB::table('agent_application_histories')->where('application_id', $applicationId)->count());
    }

    public function test_rejection_cooldown_requires_expiry_or_authorized_release(): void
    {
        $customerId = $this->user();
        $ownerId = $this->administrator(true);
        $service = $this->app->make(AgentApplicationService::class);
        $service->submit($customerId, $this->customerContext($customerId, 'agent-submit-request-0010', 'agent-submit-correlation-0010'));
        $applicationId = (int) DB::table('agent_applications')->where('customer_id', $customerId)->value('id');
        $service->claim($applicationId, $this->adminContext($ownerId, 'agent-claim-request-0010', 'agent-claim-correlation-0010'));
        $service->reject(
            $applicationId,
            $this->adminContext($ownerId, 'agent-reject-request-0010', 'agent-reject-correlation-0010', 'criteria_not_met', 'Required criteria were not met.'),
        );

        try {
            $service->submit($customerId, $this->customerContext($customerId, 'agent-submit-request-0011', 'agent-submit-correlation-0011'));
            self::fail('Expected active cooldown.');
        } catch (RuntimeException $exception) {
            self::assertSame('Agent reapplication cooldown is still active.', $exception->getMessage());
        }

        $service->releaseReapplication(
            $applicationId,
            $this->adminContext($ownerId, 'agent-release-request-0010', 'agent-release-correlation-0010', 'manual_release', 'Owner approved early reapplication.'),
        );
        $service->submit($customerId, $this->customerContext($customerId, 'agent-submit-request-0012', 'agent-submit-correlation-0012'));

        self::assertSame(2, DB::table('agent_applications')->where('customer_id', $customerId)->count());
        self::assertSame(2, (int) DB::table('agent_applications')->where('customer_id', $customerId)->max('application_version'));
    }

    public function test_explicit_deny_overrides_role_grant_and_preserves_submitted_state(): void
    {
        $customerId = $this->user();
        $administratorId = $this->administrator();
        $this->grantPermission($administratorId, 'agents.applications.review');
        $permissionId = (int) DB::table('permissions')->where('code', 'agents.applications.review')->value('id');
        $now = now('UTC');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'changed_by_administrator_id' => null,
            'reason_code' => 'restricted',
            'reason' => 'Review access denied.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $service = $this->app->make(AgentApplicationService::class);
        $service->submit($customerId, $this->customerContext($customerId, 'agent-submit-request-0020', 'agent-submit-correlation-0020'));
        $applicationId = (int) DB::table('agent_applications')->where('customer_id', $customerId)->value('id');

        try {
            $service->claim($applicationId, $this->adminContext($administratorId, 'agent-claim-request-0020', 'agent-claim-correlation-0020'));
            self::fail('Expected explicit deny.');
        } catch (AuthorizationException) {
            self::assertSame('submitted', DB::table('agent_applications')->where('id', $applicationId)->value('state'));
            self::assertNull(DB::table('agent_applications')->where('id', $applicationId)->value('claimed_at'));
        }
    }

    public function test_suspension_restoration_and_duplicate_click_create_one_effect_each(): void
    {
        $agentId = $this->approvedAgent();
        $ownerId = (int) DB::table('administrators')->where('is_owner', true)->value('id');
        $service = $this->app->make(AgentProfileService::class);
        $suspendContext = $this->adminContext($ownerId, 'agent-status-request-0030', 'agent-status-correlation-0030', 'risk_suspend', 'Agent suspended after review.');

        $suspended = $service->transitionStatus($agentId, AgentStatus::Suspended, $suspendContext);
        $replay = $service->transitionStatus($agentId, AgentStatus::Suspended, $suspendContext);
        $restored = $service->transitionStatus(
            $agentId,
            AgentStatus::Active,
            $this->adminContext($ownerId, 'agent-status-request-0031', 'agent-status-correlation-0031', 'risk_cleared', 'Agent restored.'),
        );

        self::assertTrue($suspended->changed);
        self::assertTrue($replay->replayed);
        self::assertTrue($restored->changed);
        self::assertSame('active', DB::table('agent_profiles')->where('user_id', $agentId)->value('status'));
        self::assertNull(DB::table('agent_profiles')->where('user_id', $agentId)->value('suspended_at'));
        self::assertSame(2, DB::table('agent_status_histories')->count());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'agent.profile.status')->count());
    }

    public function test_second_active_application_is_rejected_without_creating_history(): void
    {
        $customerId = $this->user();
        $service = $this->app->make(AgentApplicationService::class);
        $service->submit($customerId, $this->customerContext($customerId, 'agent-submit-request-0040', 'agent-submit-correlation-0040'));

        $this->expectException(RuntimeException::class);
        $service->submit($customerId, $this->customerContext($customerId, 'agent-submit-request-0041', 'agent-submit-correlation-0041'));
    }

    private function approvedAgent(): int
    {
        $customerId = $this->user();
        $ownerId = $this->administrator(true);
        $service = $this->app->make(AgentApplicationService::class);
        $service->submit($customerId, $this->customerContext($customerId, 'agent-submit-request-0090', 'agent-submit-correlation-0090'));
        $applicationId = (int) DB::table('agent_applications')->where('customer_id', $customerId)->value('id');
        $service->claim($applicationId, $this->adminContext($ownerId, 'agent-claim-request-0090', 'agent-claim-correlation-0090'));
        $service->approve(
            $applicationId,
            'default',
            $this->adminContext($ownerId, 'agent-approve-request-0090', 'agent-approve-correlation-0090', 'approved', 'Approved for test setup.'),
        );

        return $customerId;
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

    private function grantPermission(int $administratorId, string $permissionCode): void
    {
        $now = now('UTC');
        $roleId = (int) DB::table('roles')->where('code', 'support')->value('id');
        $permissionId = (int) DB::table('permissions')->where('code', $permissionCode)->value('id');
        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function customerContext(int $customerId, string $fingerprint, string $correlationId): AgentChangeContext
    {
        return new AgentChangeContext($fingerprint, $correlationId, 'customer_request', actorUserId: $customerId);
    }

    private function adminContext(
        int $administratorId,
        string $fingerprint,
        string $correlationId,
        string $reasonCode = 'review_action',
        ?string $reason = null,
    ): AgentChangeContext {
        return new AgentChangeContext(
            $fingerprint,
            $correlationId,
            $reasonCode,
            $reason,
            actorAdministratorId: $administratorId,
        );
    }
}
