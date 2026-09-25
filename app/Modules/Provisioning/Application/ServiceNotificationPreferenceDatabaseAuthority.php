<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\Connection;
use Throwable;

/** @requirement SVC-013 DAT-003 SEC-002 QUA-004 */
final readonly class ServiceNotificationPreferenceDatabaseAuthority
{
    private const AUTHORITY = 'service_notification_preference_write_v1';

    public function __construct(private ServiceOperationalDatabaseCapability $capability) {}

    public function apply(
        Connection $connection,
        int $actorUserId,
        ?int $serviceSubscriptionId,
        string $scopeKeyHash,
        string $notificationType,
        string $thresholdCode,
        bool $enabled,
        string $requestKeyHash,
        string $payloadHash,
        string $correlationId,
        string $timestamp,
    ): void {
        $this->capability->apply($connection);
        try {
            $connection->statement(<<<'SQL'
SET @app_service_notification_preference_authority = ?,
    @app_service_notification_preference_actor_user_id = ?,
    @app_service_notification_preference_service_id = ?,
    @app_service_notification_preference_scope_hash = ?,
    @app_service_notification_preference_type = ?,
    @app_service_notification_preference_threshold = ?,
    @app_service_notification_preference_enabled = ?,
    @app_service_notification_preference_request_hash = ?,
    @app_service_notification_preference_payload_hash = ?,
    @app_service_notification_preference_correlation_id = ?,
    @app_service_notification_preference_timestamp = ?
SQL, [
                self::AUTHORITY,
                $actorUserId,
                $serviceSubscriptionId,
                $scopeKeyHash,
                $notificationType,
                $thresholdCode,
                $enabled ? 1 : 0,
                $requestKeyHash,
                $payloadHash,
                $correlationId,
                $timestamp,
            ]);
        } catch (Throwable $exception) {
            $this->clear($connection);
            throw $exception;
        }
    }

    public function clear(Connection $connection): void
    {
        try {
            $connection->statement(<<<'SQL'
SET @app_service_notification_preference_authority = NULL,
    @app_service_notification_preference_actor_user_id = NULL,
    @app_service_notification_preference_service_id = NULL,
    @app_service_notification_preference_scope_hash = NULL,
    @app_service_notification_preference_type = NULL,
    @app_service_notification_preference_threshold = NULL,
    @app_service_notification_preference_enabled = NULL,
    @app_service_notification_preference_request_hash = NULL,
    @app_service_notification_preference_payload_hash = NULL,
    @app_service_notification_preference_correlation_id = NULL,
    @app_service_notification_preference_timestamp = NULL
SQL);
        } finally {
            $this->capability->clear($connection);
        }
    }
}
