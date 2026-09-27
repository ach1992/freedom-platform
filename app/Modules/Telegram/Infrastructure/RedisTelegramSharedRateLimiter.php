<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramSharedRateLimiter;
use App\Modules\Telegram\Application\TelegramRateLimitDecision;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Redis\RedisManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class RedisTelegramSharedRateLimiter implements TelegramSharedRateLimiter
{
    private const SINGLE_LUA = <<<'LUA'
        local stored = redis.call('GET', KEYS[1])
        local current = tonumber(stored or '0')
        local limit = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        if stored and (not current or current < 1) then
            return {-1, -1}
        end
        local ttl_ms = -2
        if stored then
            ttl_ms = tonumber(redis.call('PTTL', KEYS[1]) or '-1')
            if ttl_ms < 1 then
                return {-1, ttl_ms}
            end
        end
        if current >= limit then
            return {0, math.floor((ttl_ms + 999) / 1000)}
        end
        local value = redis.call('INCR', KEYS[1])
        if value == 1 then
            redis.call('EXPIRE', KEYS[1], window)
        end
        return {1, 0}
        LUA;

    private const OUTBOUND_LUA = <<<'LUA'
        local global_stored = redis.call('GET', KEYS[1])
        local chat_stored = redis.call('GET', KEYS[2])
        local global_current = tonumber(global_stored or '0')
        local chat_current = tonumber(chat_stored or '0')
        local global_limit = tonumber(ARGV[1])
        local global_window = tonumber(ARGV[2])
        local chat_limit = tonumber(ARGV[3])
        local chat_window = tonumber(ARGV[4])

        if global_stored and (not global_current or global_current < 1) then
            return {-1, -1}
        end
        if chat_stored and (not chat_current or chat_current < 1) then
            return {-1, -1}
        end

        local global_ttl = -2
        if global_stored then
            global_ttl = tonumber(redis.call('PTTL', KEYS[1]) or '-1')
            if global_ttl < 1 then
                return {-1, global_ttl}
            end
        end
        local chat_ttl = -2
        if chat_stored then
            chat_ttl = tonumber(redis.call('PTTL', KEYS[2]) or '-1')
            if chat_ttl < 1 then
                return {-1, chat_ttl}
            end
        end

        if global_current >= global_limit or chat_current >= chat_limit then
            local wait_ms = 0
            if global_current >= global_limit then
                wait_ms = math.max(wait_ms, global_ttl)
            end
            if chat_current >= chat_limit then
                wait_ms = math.max(wait_ms, chat_ttl)
            end
            return {0, math.floor((wait_ms + 999) / 1000)}
        end

        local global_value = redis.call('INCR', KEYS[1])
        if global_value == 1 then
            redis.call('EXPIRE', KEYS[1], global_window)
        end
        local chat_value = redis.call('INCR', KEYS[2])
        if chat_value == 1 then
            redis.call('EXPIRE', KEYS[2], chat_window)
        end
        return {1, 0}
        LUA;

    public function __construct(
        private RedisManager $redis,
        private Repository $config,
    ) {}

    public function consumeInteraction(int $userId): TelegramRateLimitDecision
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Telegram interaction rate-limit user identity is invalid.');
        }

        [$limit, $window] = $this->pair('interaction.max_attempts', 'interaction.window_seconds');

        return $this->single(
            $this->prefix().'interaction:'.hash('sha256', (string) $userId),
            $limit,
            $window,
        );
    }

    public function reserveOutbound(int $recipientChatId): TelegramRateLimitDecision
    {
        if ($recipientChatId === 0) {
            throw new InvalidArgumentException('Telegram outbound rate-limit chat identity is invalid.');
        }

        [$globalLimit, $globalWindow] = $this->pair(
            'outbound.global_max_attempts',
            'outbound.global_window_seconds',
        );
        [$chatLimit, $chatWindow] = $this->pair(
            'outbound.chat_max_attempts',
            'outbound.chat_window_seconds',
        );
        $prefix = $this->prefix();
        $result = $this->redis->connection()->command('eval', [
            self::OUTBOUND_LUA,
            [
                $prefix.'outbound:global',
                $prefix.'outbound:chat:'.hash('sha256', (string) $recipientChatId),
                (string) $globalLimit,
                (string) $globalWindow,
                (string) $chatLimit,
                (string) $chatWindow,
            ],
            2,
        ]);

        return $this->decision($result, max($globalWindow, $chatWindow));
    }

    private function single(string $key, int $limit, int $window): TelegramRateLimitDecision
    {
        $result = $this->redis->connection()->command('eval', [
            self::SINGLE_LUA,
            [$key, (string) $limit, (string) $window],
            1,
        ]);

        return $this->decision($result, $window);
    }

    private function decision(mixed $result, int $maximumRetryAfter): TelegramRateLimitDecision
    {
        if (! is_array($result) || count($result) !== 2) {
            throw new RuntimeException('Telegram shared rate limiter returned an invalid response.');
        }

        $allowed = filter_var($result[0], FILTER_VALIDATE_INT);
        $retryAfter = filter_var($result[1], FILTER_VALIDATE_INT);
        if (! is_int($allowed) || ! is_int($retryAfter)) {
            throw new RuntimeException('Telegram shared rate limiter returned a non-integer response.');
        }
        if ($allowed === 1 && $retryAfter === 0) {
            return TelegramRateLimitDecision::allowed();
        }
        if ($allowed === 0 && $retryAfter >= 1 && $retryAfter <= $maximumRetryAfter) {
            return TelegramRateLimitDecision::limited($retryAfter);
        }

        throw new RuntimeException('Telegram shared rate limiter returned an invalid decision.');
    }

    private function prefix(): string
    {
        $prefix = $this->config->get('telegram.rate_limits.prefix');
        if (! is_string($prefix)
            || preg_match('/\A[A-Za-z0-9:_.-]{4,128}\z/', $prefix) !== 1
            || ! str_ends_with($prefix, ':')) {
            throw new RuntimeException('Telegram shared rate-limit prefix is invalid.');
        }

        return $prefix;
    }

    /** @return array{0:int,1:int} */
    private function pair(string $limitKey, string $windowKey): array
    {
        $limit = filter_var($this->config->get('telegram.rate_limits.'.$limitKey), FILTER_VALIDATE_INT);
        $window = filter_var($this->config->get('telegram.rate_limits.'.$windowKey), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 10_000) {
            throw new RuntimeException('Telegram shared rate-limit maximum is invalid.');
        }
        if (! is_int($window) || $window < 1 || $window > 86_400) {
            throw new RuntimeException('Telegram shared rate-limit window is invalid.');
        }

        return [$limit, $window];
    }
}
