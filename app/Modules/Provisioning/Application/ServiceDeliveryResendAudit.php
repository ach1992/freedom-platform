<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class ServiceDeliveryResendAudit
{
    public const ACTION = 'service.delivery.resend.accepted';

    private const AUTHORITY = 'service_delivery_resend_audit_v1';

    public function __construct(private Clock $clock) {}

    /**
     * @param  array{lifecycle_state:string,lifecycle_version:int,remote_identity_generation:int}  $before
     */
    public function record(
        Connection $connection,
        string $servicePublicId,
        ServiceDeliveryAttemptReceipt $attempt,
        ServiceDeliveryResendContext $context,
        array $before,
    ): ServiceDeliveryResendReceipt {
        if ($attempt->purpose->value !== 'resend') {
            throw new RuntimeException('Service delivery resend audit requires a Resend Delivery Attempt.');
        }

        $this->setAuthority($connection, $attempt->attemptPublicId, $context->requestHash());
        try {
            $auditLogId = (int) $connection->table('audit_logs')->insertGetId([
                'actor_type' => $context->actorType(),
                'actor_id' => (string) $context->actorId(),
                'action' => self::ACTION,
                'target_type' => 'service_subscription',
                'target_id' => $servicePublicId,
                'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_safe_data' => json_encode([
                    'delivery_attempt_public_id' => $attempt->attemptPublicId,
                    'delivery_purpose' => $attempt->purpose->value,
                    'target_remote_identity_generation' => $attempt->targetRemoteIdentityGeneration,
                    'target_lifecycle_version' => $attempt->targetLifecycleVersion,
                    'outbox_event_id' => $attempt->outboxEventId,
                ], JSON_THROW_ON_ERROR),
                'reason_code' => $context->reasonCode,
                'reason' => $context->reason,
                'correlation_id' => $context->correlationId,
                'request_fingerprint' => $context->requestHash(),
                'created_at' => $this->timestamp(),
            ]);
        } finally {
            $this->clearAuthority($connection);
        }

        if ($auditLogId < 1) {
            throw new RuntimeException('Service delivery resend audit log was not created.');
        }

        return new ServiceDeliveryResendReceipt($attempt, $auditLogId, false);
    }

    public function existing(
        Connection $connection,
        string $servicePublicId,
        ServiceDeliveryAttemptReceipt $attempt,
        ServiceDeliveryResendContext $context,
        bool $lock = false,
    ): ?ServiceDeliveryResendReceipt {
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
            throw new RuntimeException('Service delivery resend audit request fingerprint conflict.');
        }

        $after = $this->decode($row->after_safe_data);
        if (($after['delivery_attempt_public_id'] ?? null) !== $attempt->attemptPublicId
            || ($after['delivery_purpose'] ?? null) !== 'resend'
            || ($after['target_remote_identity_generation'] ?? null) !== $attempt->targetRemoteIdentityGeneration
            || ($after['target_lifecycle_version'] ?? null) !== $attempt->targetLifecycleVersion
            || ($after['outbox_event_id'] ?? null) !== $attempt->outboxEventId) {
            throw new RuntimeException('Service delivery resend audit is detached from its Delivery Attempt.');
        }

        return new ServiceDeliveryResendReceipt(
            $attempt,
            $this->positiveInt($row->id, 'Audit log ID'),
            true,
        );
    }

    private function setAuthority(Connection $connection, string $attemptPublicId, string $requestHash): void
    {
        $connection->statement('SET @app_service_delivery_resend_audit_authority = ?', [self::AUTHORITY]);
        $connection->statement('SET @app_service_delivery_resend_attempt_public_id = ?', [$attemptPublicId]);
        $connection->statement('SET @app_service_delivery_resend_request_hash = ?', [$requestHash]);
    }

    private function clearAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_service_delivery_resend_audit_authority = NULL');
        $connection->statement('SET @app_service_delivery_resend_attempt_public_id = NULL');
        $connection->statement('SET @app_service_delivery_resend_request_hash = NULL');
    }

    /** @return array<string, int|string> */
    private function decode(?string $encoded): array
    {
        if ($encoded === null) {
            throw new RuntimeException('Stored Service delivery resend audit data is missing.');
        }

        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Stored Service delivery resend audit data is invalid.');
        }

        $safe = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || (! is_int($value) && ! is_string($value))) {
                throw new RuntimeException('Stored Service delivery resend audit data is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
