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

    private const BIND = 'service_notification_bind_v1';

    private const SCHEDULE_RETRY = 'service_notification_schedule_retry_v1';

    private const TRANSITION = 'service_notification_transition_v1';

    public static function create(
        Connection $connection,
        int $serviceId,
        string $episodeKeyHash,
        string $notificationType,
        string $thresholdCode,
        string $cycleKeyHash,
        string $sourceType,
        ?int $sourceId,
        string $timestamp,
        string $correlationId,
    ): void {
        self::set(
            $connection,
            self::CREATE,
            null,
            $serviceId,
            null,
            null,
            $episodeKeyHash,
            $notificationType,
            $thresholdCode,
            $cycleKeyHash,
            $sourceType,
            $sourceId,
            null,
            'triggered',
            null,
            $timestamp,
            $correlationId,
        );
    }

    public static function bind(
        Connection $connection,
        int $stateId,
        int $serviceId,
        int $attemptId,
        int $retryOrdinal,
        string $correlationId,
    ): void {
        self::set(
            $connection,
            self::BIND,
            $stateId,
            $serviceId,
            $attemptId,
            $retryOrdinal,
            null,
            null,
            null,
            null,
            null,
            null,
            'triggered',
            'triggered',
            null,
            null,
            $correlationId,
        );
    }

    public static function scheduleRetry(
        Connection $connection,
        int $stateId,
        int $serviceId,
        string $nextRetryAt,
        string $timestamp,
        string $correlationId,
    ): void {
        self::set(
            $connection,
            self::SCHEDULE_RETRY,
            $stateId,
            $serviceId,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'triggered',
            'triggered',
            $nextRetryAt,
            $timestamp,
            $correlationId,
        );
    }

    public static function transition(
        Connection $connection,
        int $stateId,
        int $serviceId,
        string $fromState,
        string $toState,
        string $timestamp,
        string $correlationId,
    ): void {
        self::set(
            $connection,
            self::TRANSITION,
            $stateId,
            $serviceId,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $fromState,
            $toState,
            null,
            $timestamp,
            $correlationId,
        );
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
    @app_service_notification_type = NULL,
    @app_service_notification_threshold_code = NULL,
    @app_service_notification_cycle_key = NULL,
    @app_service_notification_source_type = NULL,
    @app_service_notification_source_id = NULL,
    @app_service_notification_from_state = NULL,
    @app_service_notification_to_state = NULL,
    @app_service_notification_next_retry_at = NULL,
    @app_service_notification_timestamp = NULL,
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
        ?string $notificationType,
        ?string $thresholdCode,
        ?string $cycleKeyHash,
        ?string $sourceType,
        ?int $sourceId,
        ?string $fromState,
        ?string $toState,
        ?string $nextRetryAt,
        ?string $timestamp,
        string $correlationId,
    ): void {
        if ($serviceId < 1 || $correlationId === '') {
            throw new RuntimeException('Service notification database authority is incomplete.');
        }
        foreach ([$episodeKeyHash, $cycleKeyHash] as $hash) {
            if ($hash !== null && preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                throw new RuntimeException('Service notification hash authority is invalid.');
            }
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
    @app_service_notification_type = ?,
    @app_service_notification_threshold_code = ?,
    @app_service_notification_cycle_key = ?,
    @app_service_notification_source_type = ?,
    @app_service_notification_source_id = ?,
    @app_service_notification_from_state = ?,
    @app_service_notification_to_state = ?,
    @app_service_notification_next_retry_at = ?,
    @app_service_notification_timestamp = ?,
    @app_service_notification_correlation_id = ?
SQL,
                [
                    $authority,
                    $stateId,
                    $serviceId,
                    $attemptId,
                    $retryOrdinal,
                    $episodeKeyHash,
                    $notificationType,
                    $thresholdCode,
                    $cycleKeyHash,
                    $sourceType,
                    $sourceId,
                    $fromState,
                    $toState,
                    $nextRetryAt,
                    $timestamp,
                    $correlationId,
                ],
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
