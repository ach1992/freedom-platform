<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramSupportCustomerRateLimiter;
use App\Modules\Telegram\Application\TelegramSupportCustomerRateLimitDecision;
use App\Modules\Telegram\Application\TelegramSupportCustomerRateLimitScope;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\RedisManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class RedisTelegramSupportCustomerRateLimiter implements TelegramSupportCustomerRateLimiter
{
    private const LUA = <<<'LUA'
        local current = tonumber(redis.call('GET', KEYS[1]) or '0')
        local limit = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        if current >= limit then
            local ttl_ms = tonumber(redis.call('PTTL', KEYS[1]) or '-1')
            if ttl_ms < 1 then
                return {-1, ttl_ms}
            end
            return {0, math.floor((ttl_ms + 999) / 1000)}
        end
        local value = redis.call('INCR', KEYS[1])
        if value == 1 then
            redis.call('EXPIRE', KEYS[1], window)
        end
        return {1, 0}
        LUA;

    public function __construct(
        private RedisManager $redis,
        private Repository $config,
    ) {}

    public function consume(int $userId, TelegramSupportCustomerRateLimitScope $scope): TelegramSupportCustomerRateLimitDecision
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Telegram Support rate-limit user identity is invalid.');
        }

        [$limit, $window] = $this->configuration($scope);
        $key = $this->prefix().hash('sha256', $scope->value."\0".$userId);
        $result = $this->redis->connection()->command('eval', [
            self::LUA,
            [$key, (string) $limit, (string) $window],
            1,
        ]);

        if (! is_array($result) || count($result) !== 2) {
            throw new RuntimeException('Telegram Support rate limiter returned an invalid response.');
        }

        $allowed = filter_var($result[0], FILTER_VALIDATE_INT);
        $retryAfter = filter_var($result[1], FILTER_VALIDATE_INT);
        if (! is_int($allowed) || ! is_int($retryAfter)) {
            throw new RuntimeException('Telegram Support rate limiter returned a non-integer response.');
        }
        if ($allowed === 1 && $retryAfter === 0) {
            return TelegramSupportCustomerRateLimitDecision::allowed();
        }
        if ($allowed === 0 && $retryAfter >= 1 && $retryAfter <= $window) {
            return TelegramSupportCustomerRateLimitDecision::limited($retryAfter);
        }

        throw new RuntimeException('Telegram Support rate limiter returned an invalid decision.');
    }

    private function prefix(): string
    {
        $prefix = $this->config->get('support.rate_limits.prefix');
        if (! is_string($prefix)
            || preg_match('/\A[A-Za-z0-9:_.-]{4,128}\z/', $prefix) !== 1
            || ! str_ends_with($prefix, ':')) {
            throw new RuntimeException('Telegram Support rate-limit prefix is invalid.');
        }

        return $prefix;
    }

    /** @return array{0:int,1:int} */
    private function configuration(TelegramSupportCustomerRateLimitScope $scope): array
    {
        $base = 'support.rate_limits.'.$scope->value;
        $limit = filter_var($this->config->get($base.'.max_attempts'), FILTER_VALIDATE_INT);
        $window = filter_var($this->config->get($base.'.window_seconds'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            throw new RuntimeException('Telegram Support rate-limit maximum is invalid.');
        }
        if (! is_int($window) || $window < 1 || $window > 86_400) {
            throw new RuntimeException('Telegram Support rate-limit window is invalid.');
        }

        return [$limit, $window];
    }
}
