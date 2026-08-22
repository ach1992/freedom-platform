<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\Connection;
use Throwable;

/** @requirement SVC-007 DAT-003 SEC-002 */
final class ServiceAutoRenewDatabaseAuthority
{
    private const POLICY = 'service_auto_renew_policy_v1';

    private const CONFIGURATION = 'service_auto_renew_configuration_v1';

    private const SETTLEMENT = 'service_auto_renew_settlement_v1';

    private const OBSERVATION = 'service_auto_renew_observation_v1';

    public static function policy(
        Connection $connection,
        int $administratorId,
        int $planOfferingId,
        string $correlationId,
    ): void {
        self::set($connection, self::POLICY, $administratorId, $planOfferingId, null, null, $correlationId);
    }

    public static function configuration(
        Connection $connection,
        int $actorUserId,
        int $serviceSubscriptionId,
        ?int $quoteId,
        string $correlationId,
    ): void {
        self::set($connection, self::CONFIGURATION, $actorUserId, $serviceSubscriptionId, $quoteId, null, $correlationId);
    }

    public static function settlement(
        Connection $connection,
        int $configurationId,
        int $attemptId,
        string $correlationId,
    ): void {
        self::set($connection, self::SETTLEMENT, null, $configurationId, null, $attemptId, $correlationId);
    }

    public static function observation(
        Connection $connection,
        int $configurationId,
        ?int $attemptId,
        string $correlationId,
    ): void {
        self::set($connection, self::OBSERVATION, null, $configurationId, null, $attemptId, $correlationId);
    }

    public static function clear(Connection $connection): void
    {
        try {
            $connection->statement(<<<'SQL'
SET @app_service_auto_renew_authority = NULL,
    @app_service_auto_renew_actor_id = NULL,
    @app_service_auto_renew_scope_id = NULL,
    @app_service_auto_renew_quote_id = NULL,
    @app_service_auto_renew_attempt_id = NULL,
    @app_service_auto_renew_correlation_id = NULL,
    @app_service_operational_capability = NULL
SQL);
        } catch (Throwable $exception) {
            self::disconnect($connection);
            throw $exception;
        }
    }

    private static function set(
        Connection $connection,
        string $authority,
        ?int $actorId,
        int $scopeId,
        ?int $quoteId,
        ?int $attemptId,
        string $correlationId,
    ): void {
        (new ServiceOperationalDatabaseCapability)->apply($connection);

        try {
            $connection->statement(
                <<<'SQL'
SET @app_service_auto_renew_authority = ?,
    @app_service_auto_renew_actor_id = ?,
    @app_service_auto_renew_scope_id = ?,
    @app_service_auto_renew_quote_id = ?,
    @app_service_auto_renew_attempt_id = ?,
    @app_service_auto_renew_correlation_id = ?
SQL,
                [$authority, $actorId, $scopeId, $quoteId, $attemptId, $correlationId],
            );
        } catch (Throwable $exception) {
            (new ServiceOperationalDatabaseCapability)->clear($connection);
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
