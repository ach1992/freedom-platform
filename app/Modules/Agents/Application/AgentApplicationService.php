<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Agents\Domain\AgentApplicationState;
use App\Shared\Application\Clock;
use DateInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class AgentApplicationService
{
    private const REVIEW_PERMISSION = 'agents.applications.review';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private AgentMutationAudit $audit,
        private Clock $clock,
        private int $reapplicationCooldownDays = 30,
        private string $defaultPricingProfileCode = 'default',
    ) {
        if ($reapplicationCooldownDays < 0 || $reapplicationCooldownDays > 3650) {
            throw new RuntimeException('Agent reapplication cooldown is invalid.');
        }
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $defaultPricingProfileCode) !== 1) {
            throw new RuntimeException('Default agent pricing profile code is invalid.');
        }
    }

    /** @requirement AGT-001 SEC-002 QUA-001 */
    public function submit(int $customerId, AgentChangeContext $context): AgentMutationReceipt
    {
        if ($context->requireUser() !== $customerId || $customerId < 1) {
            throw new AuthorizationException('Agent application submission failed.');
        }

        $action = 'agent.application.submit';
        $existing = $this->audit->existing($action, $customerId, $context->requestFingerprint);
        if ($existing !== null) {
            return $existing;
        }

        return $this->idempotentTransaction($action, $customerId, $context, function (Connection $connection) use ($customerId, $context, $action): AgentMutationReceipt {
            $existing = $this->audit->existing($action, $customerId, $context->requestFingerprint, true);
            if ($existing !== null) {
                return $existing;
            }

            /** @var object{account_type: string, account_status: string}|null $customer */
            $customer = $connection->table('users')->where('id', $customerId)->lockForUpdate()->first(['account_type', 'account_status']);
            if ($customer === null || $customer->account_type !== 'customer' || $customer->account_status !== 'active') {
                throw new RuntimeException('An active customer account is required.');
            }

            if ($connection->table('agent_applications')->where('active_customer_id', $customerId)->exists()) {
                throw new RuntimeException('An active agent application already exists.');
            }

            /** @var object{state: string, reapply_allowed_at: ?string, reapplication_released_at: ?string}|null $latest */
            $latest = $connection->table('agent_applications')
                ->where('customer_id', $customerId)
                ->orderByDesc('application_version')
                ->lockForUpdate()
                ->first(['state', 'reapply_allowed_at', 'reapplication_released_at']);
            $this->assertReapplicationAllowed($latest);

            $version = (int) $connection->table('agent_applications')->where('customer_id', $customerId)->max('application_version') + 1;
            $now = $this->timestamp();
            $applicationId = (int) $connection->table('agent_applications')->insertGetId([
                'customer_id' => $customerId,
                'active_customer_id' => $customerId,
                'state' => AgentApplicationState::Submitted->value,
                'claimed_by_administrator_id' => null,
                'decided_by_administrator_id' => null,
                'decision_reason_code' => null,
                'decision_reason' => null,
                'application_version' => $version,
                'submitted_at' => $now,
                'claimed_at' => null,
                'decided_at' => null,
                'reapply_allowed_at' => null,
                'reapplication_released_at' => null,
                'reapplication_released_by_administrator_id' => null,
                'reapplication_release_reason_code' => null,
                'reapplication_release_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->history($connection, $applicationId, null, AgentApplicationState::Submitted, $context);

            return $this->audit->record($connection, $action, $customerId, $context, [], [
                'application_id' => $applicationId,
                'application_version' => $version,
                'state' => AgentApplicationState::Submitted->value,
            ]);
        });
    }

    /** @requirement AGT-002 ACL-002 SEC-002 */
    public function claim(int $applicationId, AgentChangeContext $context): AgentMutationReceipt
    {
        $administratorId = $context->requireAdministrator();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);

        return $this->reviewTransition(
            'agent.application.claim',
            $applicationId,
            $context,
            AgentApplicationState::Submitted,
            AgentApplicationState::UnderReview,
            function (Connection $connection, AgentApplicationRecord $application) use ($applicationId, $administratorId): void {
                $connection->table('agent_applications')->where('id', $applicationId)->update([
                    'state' => AgentApplicationState::UnderReview->value,
                    'claimed_by_administrator_id' => $administratorId,
                    'claimed_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
            },
        );
    }

    /** @requirement AGT-002 ACL-002 SEC-002 */
    public function releaseReview(int $applicationId, AgentChangeContext $context): AgentMutationReceipt
    {
        $administratorId = $context->requireAdministrator();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);

        return $this->reviewTransition(
            'agent.application.release_review',
            $applicationId,
            $context,
            AgentApplicationState::UnderReview,
            AgentApplicationState::Submitted,
            function (Connection $connection, AgentApplicationRecord $application) use ($applicationId, $administratorId): void {
                $this->assertReviewerOrOwner($connection, $application, $administratorId);
                $connection->table('agent_applications')->where('id', $applicationId)->update([
                    'state' => AgentApplicationState::Submitted->value,
                    'claimed_by_administrator_id' => null,
                    'claimed_at' => null,
                    'updated_at' => $this->timestamp(),
                ]);
            },
        );
    }

    /** @requirement AGT-002 ACL-002 SEC-002 */
    public function approve(
        int $applicationId,
        ?string $pricingProfileCode,
        AgentChangeContext $context,
    ): AgentMutationReceipt {
        $administratorId = $context->requireAdministrator();
        $context->requireReason();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);
        $pricing = $pricingProfileCode === null ? $this->defaultPricingProfileCode : trim($pricingProfileCode);
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $pricing) !== 1) {
            throw new RuntimeException('Agent pricing profile code is invalid.');
        }

        return $this->reviewTransition(
            'agent.application.approve',
            $applicationId,
            $context,
            AgentApplicationState::UnderReview,
            AgentApplicationState::Approved,
            function (Connection $connection, AgentApplicationRecord $application) use ($applicationId, $administratorId, $pricing, $context): void {
                $this->assertReviewerOrOwner($connection, $application, $administratorId);
                $now = $this->timestamp();
                $connection->table('agent_profiles')->insert([
                    'user_id' => $application->customerId,
                    'approved_application_id' => $applicationId,
                    'status' => 'active',
                    'pricing_profile_code' => $pricing,
                    'approved_by_administrator_id' => $administratorId,
                    'approved_at' => $now,
                    'suspended_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $connection->table('users')->where('id', $application->customerId)->update([
                    'account_type' => 'agent',
                    'updated_at' => $now,
                ]);
                $connection->table('agent_applications')->where('id', $applicationId)->update([
                    'active_customer_id' => null,
                    'state' => AgentApplicationState::Approved->value,
                    'decided_by_administrator_id' => $administratorId,
                    'decision_reason_code' => $context->reasonCode,
                    'decision_reason' => $context->reason,
                    'decided_at' => $now,
                    'updated_at' => $now,
                ]);
            },
        );
    }

    /** @requirement AGT-002 ACL-002 SEC-002 */
    public function reject(int $applicationId, AgentChangeContext $context): AgentMutationReceipt
    {
        $administratorId = $context->requireAdministrator();
        $context->requireReason();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);

        return $this->reviewTransition(
            'agent.application.reject',
            $applicationId,
            $context,
            AgentApplicationState::UnderReview,
            AgentApplicationState::Rejected,
            function (Connection $connection, AgentApplicationRecord $application) use ($applicationId, $administratorId, $context): void {
                $this->assertReviewerOrOwner($connection, $application, $administratorId);
                $now = $this->clock->now();
                $connection->table('agent_applications')->where('id', $applicationId)->update([
                    'active_customer_id' => null,
                    'state' => AgentApplicationState::Rejected->value,
                    'decided_by_administrator_id' => $administratorId,
                    'decision_reason_code' => $context->reasonCode,
                    'decision_reason' => $context->reason,
                    'decided_at' => $now->format('Y-m-d H:i:s.u'),
                    'reapply_allowed_at' => $now->add(new DateInterval('P'.$this->reapplicationCooldownDays.'D'))->format('Y-m-d H:i:s.u'),
                    'updated_at' => $now->format('Y-m-d H:i:s.u'),
                ]);
            },
        );
    }

    /** @requirement AGT-002 ACL-002 SEC-002 */
    public function releaseReapplication(int $applicationId, AgentChangeContext $context): AgentMutationReceipt
    {
        $administratorId = $context->requireAdministrator();
        $context->requireReason();
        $this->authorizer->authorize($administratorId, self::REVIEW_PERMISSION);
        $action = 'agent.application.release_reapplication';

        return $this->idempotentTransaction($action, $applicationId, $context, function (Connection $connection) use ($applicationId, $administratorId, $context, $action): AgentMutationReceipt {
            $existing = $this->audit->existing($action, $applicationId, $context->requestFingerprint, true);
            if ($existing !== null) {
                return $existing;
            }

            /** @var object{state: string, reapplication_released_at: ?string}|null $application */
            $application = $connection->table('agent_applications')->where('id', $applicationId)->lockForUpdate()->first(['state', 'reapplication_released_at']);
            if ($application === null || $application->state !== AgentApplicationState::Rejected->value) {
                throw new RuntimeException('Only a rejected agent application can be released.');
            }

            $before = ['state' => $application->state, 'reapplication_released' => $application->reapplication_released_at !== null];
            $after = ['state' => $application->state, 'reapplication_released' => true];
            if ($application->reapplication_released_at === null) {
                $connection->table('agent_applications')->where('id', $applicationId)->update([
                    'reapplication_released_at' => $this->timestamp(),
                    'reapplication_released_by_administrator_id' => $administratorId,
                    'reapplication_release_reason_code' => $context->reasonCode,
                    'reapplication_release_reason' => $context->reason,
                    'updated_at' => $this->timestamp(),
                ]);
            }

            return $this->audit->record($connection, $action, $applicationId, $context, $before, $after);
        });
    }

    /** @requirement AGT-001 SEC-002 */
    public function withdraw(int $applicationId, AgentChangeContext $context): AgentMutationReceipt
    {
        $customerId = $context->requireUser();
        $action = 'agent.application.withdraw';

        return $this->idempotentTransaction($action, $applicationId, $context, function (Connection $connection) use ($applicationId, $customerId, $context, $action): AgentMutationReceipt {
            $existing = $this->audit->existing($action, $applicationId, $context->requestFingerprint, true);
            if ($existing !== null) {
                return $existing;
            }

            /** @var object{customer_id: int, state: string}|null $application */
            $application = $connection->table('agent_applications')->where('id', $applicationId)->lockForUpdate()->first(['customer_id', 'state']);
            if ($application === null || (int) $application->customer_id !== $customerId || $application->state !== AgentApplicationState::Submitted->value) {
                throw new AuthorizationException('Agent application withdrawal failed.');
            }

            $connection->table('agent_applications')->where('id', $applicationId)->update([
                'active_customer_id' => null,
                'state' => AgentApplicationState::Withdrawn->value,
                'updated_at' => $this->timestamp(),
            ]);
            $this->history($connection, $applicationId, AgentApplicationState::Submitted, AgentApplicationState::Withdrawn, $context);

            return $this->audit->record(
                $connection,
                $action,
                $applicationId,
                $context,
                ['state' => AgentApplicationState::Submitted->value],
                ['state' => AgentApplicationState::Withdrawn->value],
            );
        });
    }

    /**
     * @param  callable(Connection, AgentApplicationRecord): void  $mutation
     */
    private function reviewTransition(
        string $action,
        int $applicationId,
        AgentChangeContext $context,
        AgentApplicationState $from,
        AgentApplicationState $to,
        callable $mutation,
    ): AgentMutationReceipt {
        return $this->idempotentTransaction($action, $applicationId, $context, function (Connection $connection) use ($action, $applicationId, $context, $from, $to, $mutation): AgentMutationReceipt {
            $existing = $this->audit->existing($action, $applicationId, $context->requestFingerprint, true);
            if ($existing !== null) {
                return $existing;
            }

            /** @var object{customer_id: int, state: string, claimed_by_administrator_id: ?int}|null $row */
            $row = $connection->table('agent_applications')->where('id', $applicationId)->lockForUpdate()->first(['customer_id', 'state', 'claimed_by_administrator_id']);
            if ($row === null || $row->state !== $from->value || ! $from->canTransitionTo($to)) {
                throw new RuntimeException('Agent application transition is not allowed.');
            }

            $application = new AgentApplicationRecord(
                (int) $row->customer_id,
                $row->state,
                $row->claimed_by_administrator_id === null ? null : (int) $row->claimed_by_administrator_id,
            );
            $mutation($connection, $application);
            $this->history($connection, $applicationId, $from, $to, $context);

            return $this->audit->record(
                $connection,
                $action,
                $applicationId,
                $context,
                ['state' => $from->value],
                ['state' => $to->value],
            );
        });
    }

    /**
     * @param  callable(Connection): AgentMutationReceipt  $operation
     */
    private function idempotentTransaction(
        string $action,
        int $targetId,
        AgentChangeContext $context,
        callable $operation,
    ): AgentMutationReceipt {
        try {
            return $this->database->connection()->transaction(fn (): AgentMutationReceipt => $operation($this->database->connection()));
        } catch (QueryException $exception) {
            $existing = $this->audit->existing($action, $targetId, $context->requestFingerprint);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }
    }

    /** @param  object{state: string, reapply_allowed_at: ?string, reapplication_released_at: ?string}|null  $latest */
    private function assertReapplicationAllowed(?object $latest): void
    {
        if ($latest === null || $latest->state === AgentApplicationState::Withdrawn->value) {
            return;
        }
        if ($latest->state !== AgentApplicationState::Rejected->value) {
            throw new RuntimeException('A new agent application is not allowed after the current terminal state.');
        }
        if ($latest->reapplication_released_at !== null) {
            return;
        }

        $allowedAt = $latest->reapply_allowed_at === null ? false : strtotime($latest->reapply_allowed_at);
        if ($allowedAt === false || $this->clock->now()->getTimestamp() < $allowedAt) {
            throw new RuntimeException('Agent reapplication cooldown is still active.');
        }
    }

    private function assertReviewerOrOwner(
        Connection $connection,
        AgentApplicationRecord $application,
        int $administratorId,
    ): void {
        if ($application->claimedByAdministratorId === $administratorId) {
            return;
        }

        $owner = $connection->table('administrators')
            ->where('id', $administratorId)
            ->where('status', 'active')
            ->where('is_owner', true)
            ->exists();
        if (! $owner) {
            throw new AuthorizationException('Agent application review ownership failed.');
        }
    }

    private function history(
        Connection $connection,
        int $applicationId,
        ?AgentApplicationState $from,
        AgentApplicationState $to,
        AgentChangeContext $context,
    ): void {
        $connection->table('agent_application_histories')->insert([
            'application_id' => $applicationId,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->reason,
            'correlation_id' => $context->correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
