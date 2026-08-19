<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\Connection;
use RuntimeException;

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
        $connection->statement('SET @app_service_operational_capability = ?', [$this->value()]);
    }

    public function clear(Connection $connection): void
    {
        $connection->statement('SET @app_service_operational_capability = NULL');
    }
}
