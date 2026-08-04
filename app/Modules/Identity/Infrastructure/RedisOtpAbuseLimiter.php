<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\OtpAbuseLimiter;
use App\Modules\Identity\Application\Exceptions\OtpRateLimitExceeded;
use Illuminate\Redis\RedisManager;
use RuntimeException;

final readonly class RedisOtpAbuseLimiter implements OtpAbuseLimiter
{
    private const LUA = <<<'LUA'
        local bucket_count = #KEYS
        for index = 1, bucket_count do
            local current = tonumber(redis.call('GET', KEYS[index]) or '0')
            local limit = tonumber(ARGV[((index - 1) * 2) + 1])
            if current >= limit then
                return index
            end
        end
        for index = 1, bucket_count do
            local value = redis.call('INCR', KEYS[index])
            local window = tonumber(ARGV[((index - 1) * 2) + 2])
            if value == 1 then
                redis.call('EXPIRE', KEYS[index], window)
            end
        end
        return 0
        LUA;

    public function __construct(
        private RedisManager $redis,
        private string $prefix = 'freedom:otp-limit:',
    ) {
        if ($prefix === '') {
            throw new RuntimeException('OTP rate-limit Redis prefix must not be empty.');
        }
    }

    public function consume(array $buckets): void
    {
        if ($buckets === []) {
            throw new RuntimeException('OTP abuse limiter requires at least one bucket.');
        }

        $keys = [];
        $arguments = [];

        foreach ($buckets as $bucket) {
            $keys[] = $this->prefix.hash('sha256', $bucket->name."\0".$bucket->key);
            $arguments[] = (string) $bucket->limit;
            $arguments[] = (string) $bucket->windowSeconds;
        }

        $result = $this->redis->connection()->eval(
            self::LUA,
            [...$keys, ...$arguments],
            count($keys),
        );

        if (! is_int($result) && ! is_numeric($result)) {
            throw new RuntimeException('OTP rate limiter returned an invalid response.');
        }

        $blockedIndex = (int) $result;

        if ($blockedIndex < 0 || $blockedIndex > count($buckets)) {
            throw new RuntimeException('OTP rate limiter returned an invalid bucket index.');
        }

        if ($blockedIndex > 0) {
            throw new OtpRateLimitExceeded($buckets[$blockedIndex - 1]->name);
        }
    }
}
