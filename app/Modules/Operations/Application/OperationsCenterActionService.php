<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxRuntime;
use App\Shared\Application\OutboxRuntimeResult;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class OperationsCenterActionService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private OutboxRuntime $outbox,
        private Clock $clock,
    ) {}

    /**
     * Nudge only the canonical due Outbox dispatcher.
     *
     * review_required rows and arbitrary failed Laravel jobs remain outside this control.
     *
     * @requirement OPS-003 ACL-001 ACL-002 DAT-003 SEC-002
     */
    public function dispatchDueOutbox(
        int $actorUserId,
        int $limit,
        string $correlationId,
        string $requestKey,
    ): OutboxRuntimeResult {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            OperationsPermissions::ACTIONS_EXECUTE,
        );
        if ($limit < 1 || $limit > 100
            || preg_match('/\A[A-Za-z0-9_.:-]{8,64}\z/', $correlationId) !== 1
            || trim($requestKey) === ''
            || strlen($requestKey) > 256
        ) {
            throw new InvalidArgumentException('Operations Center action context is invalid.');
        }

        $result = $this->outbox->dispatchBatch($limit);
        $timestamp = $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        $this->database->connection()->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $administratorId,
            'action' => 'operations.outbox.dispatch_due',
            'target_type' => 'outbox_runtime',
            'target_id' => 'due',
            'before_safe_data' => null,
            'after_safe_data' => json_encode([
                'limit' => $limit,
                'examined' => $result->examined,
                'success' => $result->success,
                'retryable_failure' => $result->retryableFailure,
                'definitive_failure' => $result->definitiveFailure,
                'uncertain_result' => $result->uncertainResult,
                'due_backlog' => $result->dueBacklog,
                'review_required' => $result->reviewRequired,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'operations_center_safe_dispatch',
            'reason' => 'Bounded dispatch of currently due canonical Outbox work.',
            'correlation_id' => $correlationId,
            'request_fingerprint' => hash('sha256', 'operations.outbox.dispatch_due'."\0".$requestKey),
            'created_at' => $timestamp,
        ]);

        return $result;
    }
}
