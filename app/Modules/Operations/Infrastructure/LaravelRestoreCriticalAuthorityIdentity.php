<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreCriticalAuthorityIdentity;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class LaravelRestoreCriticalAuthorityIdentity implements RestoreCriticalAuthorityIdentity
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
    public function fingerprint(): string
    {
        $json = json_encode(
            $this->facts(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $json);
    }

    /** @return array<string, mixed> */
    private function facts(): array
    {
        $connectionName = $this->database->getDefaultConnection();
        if ($connectionName === '') {
            throw new RuntimeException('Restore database authority connection is unavailable.');
        }

        $database = config('database.connections.'.$connectionName);
        if (! is_array($database)) {
            throw new RuntimeException('Restore database authority configuration is unavailable.');
        }

        $connection = $this->database->connection($connectionName);
        $row = $connection->selectOne(
            'SELECT @@server_uid AS server_uid, DATABASE() AS database_name',
            [],
            false,
        );
        $serverUid = is_object($row) ? ($row->server_uid ?? null) : null;
        $databaseName = is_object($row) ? ($row->database_name ?? null) : null;

        if (! is_string($serverUid) || $serverUid === ''
            || ! is_string($databaseName) || $databaseName === ''
        ) {
            throw new RuntimeException('Restore database authority identity could not be attested.');
        }

        $queueDefault = $this->requiredConfigString('queue.default', 'Restore queue authority');
        $cacheDefault = $this->requiredConfigString('cache.default', 'Restore cache authority');
        $maintenanceDriver = $this->requiredConfigString('app.maintenance.driver', 'Restore maintenance driver');
        $maintenanceStore = $this->requiredConfigString('app.maintenance.store', 'Restore maintenance store');

        $queueRedisConnection = null;
        $queueName = null;
        if ($queueDefault === 'redis') {
            $queueRedisConnection = $this->requiredConfigString(
                'queue.connections.redis.connection',
                'Restore queue Redis authority',
            );
            $queueName = $this->requiredConfigString(
                'queue.connections.redis.queue',
                'Restore queue name authority',
            );
        }

        $cacheRedisConnection = null;
        $cacheRedisLockConnection = null;
        if ($cacheDefault === 'redis') {
            $cacheRedisConnection = $this->requiredConfigString(
                'cache.stores.redis.connection',
                'Restore cache Redis authority',
            );
            $cacheRedisLockConnection = $this->requiredConfigString(
                'cache.stores.redis.lock_connection',
                'Restore cache Redis lock authority',
            );
        }

        $redisNames = array_values(array_unique(array_filter([
            $queueRedisConnection,
            $cacheRedisConnection,
            $cacheRedisLockConnection,
            ...$this->maintenanceRedisConnections($maintenanceDriver, $maintenanceStore),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '')));
        sort($redisNames, SORT_STRING);

        $redis = [];
        foreach ($redisNames as $name) {
            $redis[$name] = $this->redisConnectionIdentity($name);
        }

        return [
            'database' => [
                'connection' => $connectionName,
                'driver' => $this->requiredArrayString($database, 'driver', 'Restore database driver authority'),
                'configured_url' => $this->sanitizedUrl($database['url'] ?? null, 'Restore database URL authority'),
                'host' => $this->nullableScalar($database['host'] ?? null, 'Restore database host authority'),
                'port' => $this->nullableScalar($database['port'] ?? null, 'Restore database port authority'),
                'configured_database' => $this->requiredArrayString(
                    $database,
                    'database',
                    'Restore database name authority',
                ),
                'username' => $this->nullableScalar($database['username'] ?? null, 'Restore database user authority'),
                'unix_socket' => $this->nullableScalar(
                    $database['unix_socket'] ?? null,
                    'Restore database socket authority',
                ),
                'server_uid' => $serverUid,
                'database_name' => $databaseName,
            ],
            'queue' => [
                'default' => $queueDefault,
                'redis_connection' => $queueRedisConnection,
                'queue' => $queueName,
            ],
            'cache' => [
                'default' => $cacheDefault,
                'redis_connection' => $cacheRedisConnection,
                'redis_lock_connection' => $cacheRedisLockConnection,
                'prefix' => $this->nullableScalar(config('cache.prefix'), 'Restore cache prefix authority'),
            ],
            'maintenance' => [
                'driver' => $maintenanceDriver,
                'store' => $maintenanceStore,
            ],
            'redis_runtime' => [
                'client' => $this->requiredConfigString('database.redis.client', 'Restore Redis client authority'),
                'cluster' => $this->nullableScalar(
                    config('database.redis.options.cluster'),
                    'Restore Redis cluster authority',
                ),
                'prefix' => $this->nullableScalar(
                    config('database.redis.options.prefix'),
                    'Restore Redis prefix authority',
                ),
                'connections' => $redis,
            ],
        ];
    }

    /** @return list<string> */
    private function maintenanceRedisConnections(string $driver, string $store): array
    {
        if ($driver !== 'cache') {
            return [];
        }

        $storeConfiguration = config('cache.stores.'.$store);
        if (! is_array($storeConfiguration)) {
            throw new RuntimeException('Restore maintenance cache authority is unavailable.');
        }

        if (($storeConfiguration['driver'] ?? null) !== 'redis') {
            return [];
        }

        $connection = $storeConfiguration['connection'] ?? null;
        $lockConnection = $storeConfiguration['lock_connection'] ?? null;

        if (! is_string($connection) || $connection === ''
            || ! is_string($lockConnection) || $lockConnection === ''
        ) {
            throw new RuntimeException('Restore maintenance Redis authority is invalid.');
        }

        return [$connection, $lockConnection];
    }

    /** @return array<string, mixed> */
    private function redisConnectionIdentity(string $name): array
    {
        $configuration = config('database.redis.'.$name);
        if (! is_array($configuration)) {
            throw new RuntimeException('A required Restore Redis authority is unavailable.');
        }

        return [
            'url' => $this->sanitizedUrl($configuration['url'] ?? null, 'Restore Redis URL authority'),
            'host' => $this->nullableScalar($configuration['host'] ?? null, 'Restore Redis host authority'),
            'port' => $this->nullableScalar($configuration['port'] ?? null, 'Restore Redis port authority'),
            'database' => $this->nullableScalar(
                $configuration['database'] ?? null,
                'Restore Redis database authority',
            ),
            'username' => $this->nullableScalar(
                $configuration['username'] ?? null,
                'Restore Redis user authority',
            ),
        ];
    }

    /** @return array{scheme:string,host:string,port:string,path:string,user:string}|null */
    private function sanitizedUrl(mixed $value, string $label): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException($label.' is invalid.');
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || $parts['host'] === '') {
            throw new RuntimeException($label.' is invalid.');
        }

        return [
            'scheme' => is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '',
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'path' => is_string($parts['path'] ?? null) ? $parts['path'] : '',
            'user' => is_string($parts['user'] ?? null) ? $parts['user'] : '',
        ];
    }

    private function requiredConfigString(string $key, string $label): string
    {
        $value = config($key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredArrayString(array $values, string $key, string $label): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function nullableScalar(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (string) $value;
    }
}
