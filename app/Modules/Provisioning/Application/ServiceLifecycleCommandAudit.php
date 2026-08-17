<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class ServiceLifecycleCommandAudit
{
    public const ACTION = 'service.lifecycle.command.accepted';

    private const AUTHORITY = 'service_lifecycle_command_audit_v1';

    public function __construct(private Clock $clock) {}

    public function existing(
        Connection $connection,
        string $servicePublicId,
        ServiceMutationReceipt $mutation,
        ServiceLifecycleCommandContext $context,
        bool $lock = false,
    ): ?ServiceLifecycleCommandReceipt {
        $query = $connection->table('audit_logs')
            ->where('action', self::ACTION)
            ->where('request_fingerprint', $context->requestHash());
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{id:int|string,actor_type:string,actor_id:?string,target_type:?string,target_id:?string,after_safe_data:?string,reason_code:?string,reason:?string,correlation_id:string}|null $row */
        $row = $query->first([
            'id', 'actor_type', 'actor_id', 'target_type', 'target_id', 'after_safe_data',
            'reason_code', 'reason', 'correlation_id',
        ]);
        if ($row === null) {
            return null;
        }

        if ($row->actor_type !== $context->actorType()
            || $row->actor_id !== (string) $context->actorId()
            || $row->target_type !== 'service_subscription'
            || $row->target_id !== $servicePublicId
            || $row->reason_code !== $context->reasonCode
            || $row->reason !== $context->reason
            || $row->correlation_id !== $context->correlationId) {
            throw new RuntimeException('Service lifecycle audit request fingerprint conflict.');
        }

        $after = $this->decode($row->after_safe_data);
        if (($after['operation_public_id'] ?? null) !== $mutation->operationPublicId
            || ($after['operation_type'] ?? null) !== $mutation->type->value
            || ($after['operation_generation'] ?? null) !== $mutation->generation) {
            throw new RuntimeException('Service lifecycle audit is detached from its mutation operation.');
        }

        return new ServiceLifecycleCommandReceipt(
            $mutation,
            $this->positiveInt($row->id, 'Audit log ID'),
            true,
        );
    }

    /**
     * @param  array<string, bool|int|string|null>  $before
     * @param  object{target_remote_identity_generation:int|string,target_lifecycle_version:int|string,request_key_hash:?string}  $operation
     */
    public function record(
        Connection $connection,
        string $servicePublicId,
        ServiceMutationReceipt $mutation,
        ServiceLifecycleCommandContext $context,
        array $before,
        object $operation,
    ): ServiceLifecycleCommandReceipt {
        if (! is_string($operation->request_key_hash)
            || ! hash_equals($context->requestHash(), $operation->request_key_hash)) {
            throw new RuntimeException('Service lifecycle audit request identity is inconsistent.');
        }

        $after = [
            'operation_public_id' => $mutation->operationPublicId,
            'operation_type' => $mutation->type->value,
            'operation_generation' => $mutation->generation,
            'target_remote_identity_generation' => $this->nonNegativeInt(
                $operation->target_remote_identity_generation,
                'Target remote identity generation',
            ),
            'target_lifecycle_version' => $this->nonNegativeInt(
                $operation->target_lifecycle_version,
                'Target lifecycle version',
            ),
            'accepted_state' => $mutation->state->value,
        ];

        $this->setAuthority($connection, $mutation->operationPublicId, $context->requestHash());
        try {
            $auditLogId = (int) $connection->table('audit_logs')->insertGetId([
                'actor_type' => $context->actorType(),
                'actor_id' => (string) $context->actorId(),
                'action' => self::ACTION,
                'target_type' => 'service_subscription',
                'target_id' => $servicePublicId,
                'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
                'reason_code' => $context->reasonCode,
                'reason' => $context->reason,
                'correlation_id' => $context->correlationId,
                'request_fingerprint' => $context->requestHash(),
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
            ]);
        } finally {
            $this->clearAuthority($connection);
        }

        if ($auditLogId < 1) {
            throw new RuntimeException('Service lifecycle audit log was not created.');
        }

        return new ServiceLifecycleCommandReceipt($mutation, $auditLogId, false);
    }

    private function setAuthority(Connection $connection, string $operationPublicId, string $requestHash): void
    {
        $connection->statement('SET @app_service_lifecycle_audit_authority = ?', [self::AUTHORITY]);
        $connection->statement('SET @app_service_lifecycle_operation_public_id = ?', [$operationPublicId]);
        $connection->statement('SET @app_service_lifecycle_request_hash = ?', [$requestHash]);
    }

    private function clearAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_service_lifecycle_audit_authority = NULL');
        $connection->statement('SET @app_service_lifecycle_operation_public_id = NULL');
        $connection->statement('SET @app_service_lifecycle_request_hash = NULL');
    }

    /** @return array<string, bool|int|string|null> */
    private function decode(?string $encoded): array
    {
        if ($encoded === null) {
            throw new RuntimeException('Stored Service lifecycle audit data is missing.');
        }

        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Stored Service lifecycle audit data is invalid.');
        }

        $safe = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                throw new RuntimeException('Stored Service lifecycle audit data is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private function positiveInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
