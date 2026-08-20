<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class ServiceOperationalDatabaseCapability
{
    private const CONTEXT = 'service-operational-database-authority-v1';

    public function value(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Service operational database capability key is unavailable.');
        }

        return hash_hmac('sha256', self::CONTEXT, $key);
    }

    public function expectedHash(): string
    {
        return hash('sha256', $this->value());
    }

    public function apply(Connection $connection): void
    {
        try {
            $connection->statement('SET @app_service_operational_capability = ?', [$this->value()]);
        } catch (Throwable $exception) {
            $this->disconnect($connection);
            throw $exception;
        }
    }

    public function clear(Connection $connection): void
    {
        try {
            $connection->statement(<<<'SQL'
SET @app_service_batch_authority = NULL,
    @app_service_operational_audit_authority = NULL,
    @app_service_operational_evidence_authority = NULL,
    @app_service_operational_authority = NULL,
    @app_service_operational_evidence_id = NULL,
    @app_service_operational_request_hash = NULL,
    @app_service_operational_correlation_id = NULL,
    @app_service_operational_capability = NULL
SQL);
        } catch (Throwable $exception) {
            $this->disconnect($connection);
            throw $exception;
        }
    }

    private function disconnect(Connection $connection): void
    {
        try {
            $connection->disconnect();
        } catch (Throwable) {
            $connection->setPdo(null);
            $connection->setReadPdo(null);
        }
    }
}
