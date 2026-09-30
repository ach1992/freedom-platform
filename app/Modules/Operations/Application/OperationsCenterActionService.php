<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxRuntime;
use App\Shared\Application\OutboxRuntimeResult;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class OperationsCenterActionService
{
    private const ACTION_DISPATCH_DUE = 'operations.outbox.dispatch_due';
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

        $requestFingerprint = hash('sha256', self::ACTION_DISPATCH_DUE."\0".$requestKey);
        $replayed = $this->replayedDispatchResult(
            $administratorId,
            $limit,
            $correlationId,
            $requestFingerprint,
        );
        if ($replayed !== null) {
            return $replayed;
        }

        $result = $this->outbox->dispatchBatch($limit);
        $timestamp = $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        $this->database->connection()->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $administratorId,
            'action' => self::ACTION_DISPATCH_DUE,
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
                'oldest_due_age_seconds' => $result->oldestDueAgeSeconds,
                'review_required' => $result->reviewRequired,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'operations_center_safe_dispatch',
            'reason' => 'Bounded dispatch of currently due canonical Outbox work.',
            'correlation_id' => $correlationId,
            'request_fingerprint' => $requestFingerprint,
            'created_at' => $timestamp,
        ]);

        return $result;
    }

    private function replayedDispatchResult(
        int $administratorId,
        int $limit,
        string $correlationId,
        string $requestFingerprint,
    ): ?OutboxRuntimeResult {
        /** @var object{actor_type:string,actor_id:?string,target_type:string,target_id:string,after_safe_data:?string,reason_code:?string,correlation_id:string}|null $existing */
        $existing = $this->database->connection()->table('audit_logs')
            ->where('action', self::ACTION_DISPATCH_DUE)
            ->where('request_fingerprint', $requestFingerprint)
            ->first([
                'actor_type',
                'actor_id',
                'target_type',
                'target_id',
                'after_safe_data',
                'reason_code',
                'correlation_id',
            ]);

        if ($existing === null) {
            return null;
        }

        if ($existing->actor_type !== 'administrator'
            || $existing->actor_id !== (string) $administratorId
            || $existing->target_type !== 'outbox_runtime'
            || $existing->target_id !== 'due'
            || $existing->reason_code !== 'operations_center_safe_dispatch'
            || $existing->correlation_id !== $correlationId
            || $existing->after_safe_data === null
        ) {
            throw new RuntimeException('Operations Center action request key was replayed with conflicting semantics.');
        }

        try {
            $data = json_decode($existing->after_safe_data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored Operations Center action receipt is invalid.', 0, $exception);
        }

        if (! is_array($data)
            || array_is_list($data)
            || ! array_key_exists('oldest_due_age_seconds', $data)
        ) {
            throw new RuntimeException('Stored Operations Center action receipt is invalid.');
        }

        foreach ([
            'limit',
            'examined',
            'success',
            'retryable_failure',
            'definitive_failure',
            'uncertain_result',
            'due_backlog',
            'review_required',
        ] as $key) {
            if (! array_key_exists($key, $data) || ! is_int($data[$key])) {
                throw new RuntimeException('Stored Operations Center action receipt is invalid.');
            }
        }

        if ($data['limit'] !== $limit
            || ($data['oldest_due_age_seconds'] !== null && ! is_int($data['oldest_due_age_seconds']))
        ) {
            throw new RuntimeException('Operations Center action request key was replayed with conflicting semantics.');
        }

        return new OutboxRuntimeResult(
            $data['examined'],
            $data['success'],
            $data['retryable_failure'],
            $data['definitive_failure'],
            $data['uncertain_result'],
            $data['due_backlog'],
            $data['oldest_due_age_seconds'],
            $data['review_required'],
        );
    }
}
