<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/** @requirement SVC-013 SVC-014 DAT-003 SEC-002 QUA-004 */
final class ServiceNotificationDatabaseAuthority
{
    private const CREATE = 'service_notification_create_v1';

    private const UPDATE = 'service_notification_update_v1';

    private const BIND = 'service_notification_bind_v1';

    public static function create(Connection $connection, int $serviceId, string $episodeKeyHash, string $correlationId): void
    {
        self::set($connection, self::CREATE, null, $serviceId, null, null, $episodeKeyHash, $correlationId);
    }

    public static function update(Connection $connection, int $stateId, int $serviceId, string $correlationId): void
    {
        self::set($connection, self::UPDATE, $stateId, $serviceId, null, null, null, $correlationId);
    }

    public static function bind(
        Connection $connection,
        int $stateId,
        int $serviceId,
        int $attemptId,
        int $retryOrdinal,
        string $correlationId,
    ): void {
        self::set($connection, self::BIND, $stateId, $serviceId, $attemptId, $retryOrdinal, null, $correlationId);
    }

    public static function clear(Connection $connection): void
    {
        try {
            $connection->statement(<<<'SQL'
SET @app_service_notification_authority = NULL,
    @app_service_notification_state_id = NULL,
    @app_service_notification_service_id = NULL,
    @app_service_notification_attempt_id = NULL,
    @app_service_notification_retry_ordinal = NULL,
    @app_service_notification_episode_key = NULL,
    @app_service_notification_correlation_id = NULL
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
        ?int $stateId,
        int $serviceId,
        ?int $attemptId,
        ?int $retryOrdinal,
        ?string $episodeKeyHash,
        string $correlationId,
    ): void {
        if ($serviceId < 1 || $correlationId === '') {
            throw new RuntimeException('Service notification database authority is incomplete.');
        }
        if ($episodeKeyHash !== null && preg_match('/\A[0-9a-f]{64}\z/', $episodeKeyHash) !== 1) {
            throw new RuntimeException('Service notification episode authority is invalid.');
        }

        (new ServiceOperationalDatabaseCapability)->apply($connection);
        try {
            $connection->statement(
                <<<'SQL'
SET @app_service_notification_authority = ?,
    @app_service_notification_state_id = ?,
    @app_service_notification_service_id = ?,
    @app_service_notification_attempt_id = ?,
    @app_service_notification_retry_ordinal = ?,
    @app_service_notification_episode_key = ?,
    @app_service_notification_correlation_id = ?
SQL,
                [$authority, $stateId, $serviceId, $attemptId, $retryOrdinal, $episodeKeyHash, $correlationId],
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
