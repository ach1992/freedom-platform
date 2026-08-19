<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class ServiceOperationalAudit
{
    private const AUTHORITY = 'service_operational_audit_v1';

    public function __construct(private Clock $clock) {}

    /**
     * @param  array<string, bool|int|string|null>  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    public function record(
        Connection $connection,
        string $action,
        string $targetType,
        string $targetId,
        ServiceOperationalContext $context,
        array $before,
        array $after,
    ): int {
        if (! str_starts_with($action, 'service.operational.')) {
            throw new RuntimeException('Service operational audit action is invalid.');
        }

        $connection->statement('SET @app_service_operational_audit_authority = ?', [self::AUTHORITY]);
        $connection->statement('SET @app_service_operational_request_hash = ?', [$context->requestHash()]);
        try {
            $id = (int) $connection->table('audit_logs')->insertGetId([
                'actor_type' => 'administrator',
                'actor_id' => (string) $context->actorAdministratorId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
                'reason_code' => $context->reasonCode,
                'reason' => $context->reason,
                'correlation_id' => $context->correlationId,
                'request_fingerprint' => $context->requestHash(),
                'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ]);
        } finally {
            $connection->statement('SET @app_service_operational_audit_authority = NULL');
            $connection->statement('SET @app_service_operational_request_hash = NULL');
        }
        if ($id < 1) {
            throw new RuntimeException('Service operational audit was not created.');
        }

        return $id;
    }
}
