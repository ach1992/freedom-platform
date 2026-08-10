<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Agents\Domain\AgentStatus;
use App\Shared\Application\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class AgentProfileService
{
    private const MANAGE_PERMISSION = 'agents.accounts.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private AgentMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement AGT-002 ACL-002 SEC-002 */
    public function transitionStatus(
        int $userId,
        AgentStatus $targetStatus,
        AgentChangeContext $context,
    ): AgentMutationReceipt {
        $administratorId = $context->requireAdministrator();
        $context->requireReason();
        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
        $action = 'agent.profile.status';
        $existing = $this->audit->existing($action, $userId, $context->requestFingerprint);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($userId, $targetStatus, $context, $administratorId, $action): AgentMutationReceipt {
                $this->assertActiveAdministrator($connection, $administratorId);
                $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);

                $existing = $this->audit->existing($action, $userId, $context->requestFingerprint, true);
                if ($existing !== null) {
                    return $existing;
                }

                /** @var object{id: int, status: string, suspended_at: ?string}|null $profile */
                $profile = $connection->table('agent_profiles')
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first(['id', 'status', 'suspended_at']);
                if ($profile === null) {
                    throw new RuntimeException('Agent profile was not found.');
                }

                $current = AgentStatus::tryFrom($profile->status);
                if ($current === null) {
                    throw new RuntimeException('Agent status is invalid.');
                }

                $before = [
                    'status' => $current->value,
                    'suspended_at' => $profile->suspended_at,
                ];
                $after = $current === $targetStatus
                    ? $before
                    : [
                        'status' => $targetStatus->value,
                        'suspended_at' => $targetStatus === AgentStatus::Suspended ? $this->timestamp() : null,
                    ];

                if ($current->canTransitionTo($targetStatus)) {
                    $connection->table('agent_profiles')->where('id', $profile->id)->update([
                        'status' => $targetStatus->value,
                        'suspended_at' => $after['suspended_at'],
                        'updated_at' => $this->timestamp(),
                    ]);
                    $this->history($connection, $profile->id, $current, $targetStatus, $administratorId, $context);
                }

                return $this->audit->record($connection, $action, $userId, $context, $before, $after);
            });
        } catch (QueryException $exception) {
            $existing = $this->audit->existing($action, $userId, $context->requestFingerprint);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }
    }

    private function assertActiveAdministrator(Connection $connection, int $administratorId): void
    {
        $active = $connection->table('administrators')
            ->where('id', $administratorId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->exists();

        if (! $active) {
            throw new AuthorizationException('Administrator authorization failed.');
        }
    }

    private function history(
        Connection $connection,
        int $profileId,
        AgentStatus $from,
        AgentStatus $to,
        int $administratorId,
        AgentChangeContext $context,
    ): void {
        $connection->table('agent_status_histories')->insert([
            'agent_profile_id' => $profileId,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_administrator_id' => $administratorId,
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
