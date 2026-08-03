<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\RedisManager;
use Throwable;

final readonly class RuntimeHealthProbe
{
    /** @requirement OPS-001 RUN-001 RUN-003 */
    public function __construct(
        private DatabaseManager $database,
        private RedisManager $redis,
    ) {}

    /** @return array<string, array{passed: bool, error_class?: class-string<Throwable>, error_code?: string}> */
    public function checks(): array
    {
        $redisRequired = config('queue.default') === 'redis' || config('cache.default') === 'redis';

        return [
            'application_key' => $this->check(fn (): bool => is_string(config('app.key')) && config('app.key') !== ''),
            'operational_timezone' => $this->check(fn (): bool => config('app.timezone') === 'UTC'),
            'business_timezone' => $this->check(fn (): bool => config('business.display_timezone') === 'Asia/Tehran'),
            'production_debug' => $this->check(fn (): bool => ! app()->environment('production') || config('app.debug') === false),
            'database' => $this->check(function (): bool {
                $this->database->connection()->selectOne('SELECT 1 AS ready');

                return true;
            }),
            'redis' => $this->check(function () use ($redisRequired): bool {
                return ! $redisRequired || $this->redis->connection()->command('ping') !== false;
            }),
            'queue_driver' => $this->check(fn (): bool => ! app()->environment('production') || config('queue.default') === 'redis'),
            'cache_driver' => $this->check(fn (): bool => ! app()->environment('production') || config('cache.default') === 'redis'),
            'storage_writable' => $this->check(fn (): bool => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'))),
        ];
    }

    /** @param array<string, array{passed: bool}> $checks */
    public function isHealthy(array $checks): bool
    {
        return array_all($checks, fn (array $check): bool => $check['passed']);
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
