<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/** @requirement SVC-010 SVC-013 DAT-003 SEC-002 QUA-004 */
final class ServiceSyncDatabaseAuthority
{
    private const RUN_CREATE = 'service_sync_run_create_v1';

    private const RUN_FINALIZE = 'service_sync_run_finalize_v1';

    private const LEASE = 'service_sync_lease_v1';

    private const SNAPSHOT = 'service_sync_snapshot_v1';

    private const ANOMALY = 'service_sync_anomaly_v1';

    private const RESOLUTION = 'service_sync_resolution_v1';

    public static function runCreate(Connection $connection, string $correlationId): void
    {
        self::set($connection, self::RUN_CREATE, null, null, null, $correlationId);
    }

    public static function runFinalize(Connection $connection, int $runId, string $correlationId): void
    {
        self::set($connection, self::RUN_FINALIZE, $runId, null, null, $correlationId);
    }

    public static function lease(Connection $connection, int $serviceId, string $leaseToken): void
    {
        self::set($connection, self::LEASE, null, $serviceId, null, $leaseToken, null, $leaseToken);
    }

    public static function snapshot(
        Connection $connection,
        int $runId,
        int $serviceId,
        string $correlationId,
        string $leaseToken,
    ): void {
        self::set($connection, self::SNAPSHOT, $runId, $serviceId, null, $correlationId, null, $leaseToken);
    }

    public static function anomaly(Connection $connection, int $runId, int $serviceId, string $correlationId): void
    {
        self::set($connection, self::ANOMALY, $runId, $serviceId, null, $correlationId);
    }

    public static function resolution(
        Connection $connection,
        int $serviceId,
        int $actorAdministratorId,
        string $requestHash,
        string $correlationId,
    ): void {
        self::set($connection, self::RESOLUTION, null, $serviceId, $actorAdministratorId, $correlationId, $requestHash);
    }

    public static function clear(Connection $connection): void
    {
        try {
            $connection->statement(<<<'SQL'
SET @app_service_sync_authority = NULL,
    @app_service_sync_run_id = NULL,
    @app_service_sync_service_id = NULL,
    @app_service_sync_actor_id = NULL,
    @app_service_sync_request_hash = NULL,
    @app_service_sync_correlation_id = NULL,
    @app_service_sync_lease_token = NULL
SQL);
            (new ServiceOperationalDatabaseCapability)->clear($connection);
        } catch (Throwable $exception) {
            self::disconnect($connection);
            throw $exception;
        }
    }

    private static function set(
        Connection $connection,
        string $authority,
        ?int $runId,
        ?int $serviceId,
        ?int $actorId,
        string $correlationId,
        ?string $requestHash = null,
        ?string $leaseToken = null,
    ): void {
        if ($correlationId === '') {
            throw new RuntimeException('Service sync database correlation authority is unavailable.');
        }
        if ($leaseToken !== null && $leaseToken === '') {
            throw new RuntimeException('Service sync database lease authority is unavailable.');
        }

        (new ServiceOperationalDatabaseCapability)->apply($connection);
        try {
            $connection->statement(
                <<<'SQL'
SET @app_service_sync_authority = ?,
    @app_service_sync_run_id = ?,
    @app_service_sync_service_id = ?,
    @app_service_sync_actor_id = ?,
    @app_service_sync_request_hash = ?,
    @app_service_sync_correlation_id = ?,
    @app_service_sync_lease_token = ?
SQL,
                [$authority, $runId, $serviceId, $actorId, $requestHash, $correlationId, $leaseToken],
            );
        } catch (Throwable $exception) {
            self::disconnect($connection);
            throw $exception;
        }
    }

    private static function disconnect(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            $connection->setPdo(null);
            $connection->setReadPdo(null);
            $connection->setDirectPdo(null);
        }
    }
}
