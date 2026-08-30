<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\RedisManager;
use Throwable;

final readonly class RuntimeHealthProbe
{
    /** @requirement OPS-001 RUN-001 RUN-003 */
    public function __construct(
        private DatabaseManager $database,
        private RedisManager $redis,
        private RuntimeDeploymentInvariants $deploymentInvariants,
    ) {}

    /** @return array<string, array{passed: bool, error_class?: class-string<Throwable>, error_code?: string}> */
    public function checks(): array
    {
        $redisRequired = $this->redisRequired();

        return [
            'application_key' => $this->check(fn (): bool => is_string(config('app.key')) && config('app.key') !== ''),
            'operational_timezone' => $this->check(fn (): bool => config('app.timezone') === 'UTC'),
            'business_timezone' => $this->check(fn (): bool => config('business.display_timezone') === 'Asia/Tehran'),
            'production_debug' => $this->check(fn (): bool => ! app()->environment('production') || config('app.debug') === false),
            'database' => $this->check(function (): bool {
                $this->database->connection()->selectOne('SELECT 1 AS ready');

                return true;
            }),
            'database_runtime' => $this->check(fn (): bool => $this->databaseRuntimeCompatible()),
            'database_session' => $this->check(fn (): bool => $this->databaseSessionCompatible()),
            'redis' => $this->check(fn (): bool => $this->redisReachable($redisRequired)),
            'redis_authentication' => $this->check(fn (): bool => $this->redisAuthenticationCompatible($redisRequired)),
            'queue_driver' => $this->check(fn (): bool => ! app()->environment('production') || config('queue.default') === 'redis'),
            'queue_after_commit' => $this->check(fn (): bool => $this->queueAfterCommitCompatible()),
            'queue_retry_after' => $this->check(fn (): bool => $this->queueRetryAfterCompatible()),
            'cache_driver' => $this->check(fn (): bool => ! app()->environment('production') || config('cache.default') === 'redis'),
            'storage_writable' => $this->check(fn (): bool => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'))),
        ];
    }

    /** @param array<string, array{passed: bool}> $checks */
    public function isHealthy(array $checks): bool
    {
        return array_all($checks, fn (array $check): bool => $check['passed']);
    }

    private function databaseRuntimeCompatible(): bool
    {
        $connection = $this->database->connection();
        if (! $this->databaseInvariantsRequired($connection)) {
            return true;
        }

        $facts = $connection->selectOne('SELECT VERSION() AS version, @@server_uid AS server_uid', [], false);
        if ($facts === null) {
            return false;
        }

        return $this->deploymentInvariants->mariaDbServerCompatible(
            $connection->getDriverName(),
            (string) ($facts->version ?? ''),
            (string) ($facts->server_uid ?? ''),
        );
    }

    private function databaseSessionCompatible(): bool
    {
        $connection = $this->database->connection();
        if (! $this->databaseInvariantsRequired($connection)) {
            return true;
        }

        $session = $connection->selectOne(<<<'SQL'
SELECT
    @@SESSION.character_set_connection AS character_set_connection,
    @@SESSION.collation_connection AS collation_connection,
    @@SESSION.sql_mode AS sql_mode
SQL, [], false);
        $schema = $connection->table('information_schema.SCHEMATA')
            ->where('SCHEMA_NAME', $connection->getDatabaseName())
            ->first(['DEFAULT_COLLATION_NAME']);
        $connectionName = (string) config('database.default');

        if ($session === null || $schema === null || $connectionName === '') {
            return false;
        }

        return $this->deploymentInvariants->databaseSessionCompatible(
            (string) config("database.connections.{$connectionName}.charset", ''),
            (string) config("database.connections.{$connectionName}.collation", ''),
            (string) ($session->character_set_connection ?? ''),
            (string) ($session->collation_connection ?? ''),
            (string) ($schema->DEFAULT_COLLATION_NAME ?? ''),
            (string) ($session->sql_mode ?? ''),
        );
    }

    private function databaseInvariantsRequired(Connection $connection): bool
    {
        return app()->environment('production')
            || in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function redisRequired(): bool
    {
        return config('queue.default') === 'redis' || config('cache.default') === 'redis';
    }

    private function redisReachable(bool $redisRequired): bool
    {
        if (! $redisRequired) {
            return true;
        }

        $connections = $this->requiredRedisConnections();
        if ($connections === []) {
            return false;
        }

        foreach ($connections as $connection) {
            if ($this->redis->connection($connection)->command('ping') === false) {
                return false;
            }
        }

        return true;
    }

    private function redisAuthenticationCompatible(bool $redisRequired): bool
    {
        if (! $redisRequired) {
            return true;
        }

        $connections = $this->requiredRedisConnections();
        if ($connections === []) {
            return false;
        }

        foreach ($connections as $connection) {
            if (! $this->deploymentInvariants->redisConnectionAuthenticated(
                config("database.redis.{$connection}"),
            )) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function requiredRedisConnections(): array
    {
        $connections = [];

        if (config('queue.default') === 'redis') {
            $connections[] = config('queue.connections.redis.connection');
        }
        if (config('cache.default') === 'redis') {
            $connections[] = config('cache.stores.redis.connection');
            $connections[] = config('cache.stores.redis.lock_connection');
        }

        foreach ($connections as $connection) {
            if (! is_string($connection) || $connection === '') {
                return [];
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($connections));

        return $unique;
    }

    private function queueAfterCommitCompatible(): bool
    {
        if (config('queue.default') !== 'redis') {
            return ! app()->environment('production');
        }

        return $this->deploymentInvariants->redisQueueAfterCommitCompatible(
            config('queue.connections.redis'),
        );
    }

    private function queueRetryAfterCompatible(): bool
    {
        if (config('queue.default') !== 'redis') {
            return ! app()->environment('production');
        }

        $path = base_path('deploy/supervisor/freedom-platform.conf');
        if (! is_readable($path)) {
            return false;
        }
        $template = file_get_contents($path);
        if (! is_string($template)) {
            return false;
        }

        return $this->deploymentInvariants->redisQueueRetryCompatible(
            config('queue.connections.redis'),
            $template,
        );
    }

    /** @return array{passed: bool, error_class?: class-string<Throwable>, error_code?: string} */
    private function check(callable $check): array
    {
        try {
            return ['passed' => $check() === true];
        } catch (Throwable $throwable) {
            return [
                'passed' => false,
                'error_class' => $throwable::class,
                'error_code' => (string) $throwable->getCode(),
            ];
        }
    }
}
