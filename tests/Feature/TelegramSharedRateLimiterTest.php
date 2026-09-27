<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramSharedRateLimiter;
use Illuminate\Redis\RedisManager;
use RuntimeException;
use Tests\TestCase;

final class TelegramSharedRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.rate_limits.prefix' => 'test:telegram-shared-rate:'.bin2hex(random_bytes(8)).':',
            'telegram.rate_limits.interaction.max_attempts' => 2,
            'telegram.rate_limits.interaction.window_seconds' => 60,
            'telegram.rate_limits.outbound.global_max_attempts' => 2,
            'telegram.rate_limits.outbound.global_window_seconds' => 60,
            'telegram.rate_limits.outbound.chat_max_attempts' => 1,
            'telegram.rate_limits.outbound.chat_window_seconds' => 60,
        ]);
    }

    public function test_interaction_and_outbound_budgets_are_atomic_bounded_and_scoped(): void
    {
        $limiter = $this->app->make(TelegramSharedRateLimiter::class);
        $userId = random_int(1_000_000, 2_000_000_000);

        self::assertTrue($limiter->consumeInteraction($userId)->allowed);
        self::assertTrue($limiter->consumeInteraction($userId)->allowed);
        $interactionLimited = $limiter->consumeInteraction($userId);
        self::assertFalse($interactionLimited->allowed);
        self::assertNotNull($interactionLimited->retryAfterSeconds);
        self::assertGreaterThanOrEqual(1, $interactionLimited->retryAfterSeconds);
        self::assertLessThanOrEqual(60, $interactionLimited->retryAfterSeconds);
        self::assertTrue($limiter->consumeInteraction($userId + 1)->allowed);

        self::assertTrue($limiter->reserveOutbound(901001)->allowed);
        $sameChatLimited = $limiter->reserveOutbound(901001);
        self::assertFalse($sameChatLimited->allowed);
        self::assertNotNull($sameChatLimited->retryAfterSeconds);
        self::assertTrue($limiter->reserveOutbound(901002)->allowed);

        $globalLimited = $limiter->reserveOutbound(901003);
        self::assertFalse($globalLimited->allowed);
        self::assertNotNull($globalLimited->retryAfterSeconds);
        self::assertGreaterThanOrEqual(1, $globalLimited->retryAfterSeconds);
        self::assertLessThanOrEqual(60, $globalLimited->retryAfterSeconds);
    }

    public function test_corrupt_interaction_bucket_without_expiry_below_limit_fails_closed(): void
    {
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $userId = random_int(1_000_000, 2_000_000_000);
        $key = $prefix.'interaction:'.hash('sha256', (string) $userId);
        $this->app->make(RedisManager::class)->connection()->command('set', [$key, '1']);

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramSharedRateLimiter::class)->consumeInteraction($userId);
    }

    public function test_non_positive_existing_interaction_bucket_fails_closed_even_with_expiry(): void
    {
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $userId = random_int(1_000_000, 2_000_000_000);
        $key = $prefix.'interaction:'.hash('sha256', (string) $userId);
        $redis = $this->app->make(RedisManager::class)->connection();
        $redis->command('set', [$key, '0']);
        $redis->command('expire', [$key, '60']);

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramSharedRateLimiter::class)->consumeInteraction($userId);
    }

    public function test_positive_non_integer_interaction_bucket_fails_closed_without_mutation(): void
    {
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $userId = random_int(1_000_000, 2_000_000_000);
        $key = $prefix.'interaction:'.hash('sha256', (string) $userId);
        $redis = $this->app->make(RedisManager::class)->connection();
        $redis->command('set', [$key, '1.5']);
        $redis->command('expire', [$key, '60']);
        $expiresAt = $redis->command('pexpiretime', [$key]);

        try {
            $this->app->make(TelegramSharedRateLimiter::class)->consumeInteraction($userId);
            self::fail('Redis-INCR-incompatible interaction state must fail closed.');
        } catch (RuntimeException) {
            self::assertSame('1.5', $redis->command('get', [$key]));
            self::assertSame($expiresAt, $redis->command('pexpiretime', [$key]));
        }
    }

    public function test_corrupt_outbound_global_bucket_without_expiry_below_limit_fails_closed(): void
    {
        config([
            'telegram.rate_limits.outbound.global_max_attempts' => 3,
            'telegram.rate_limits.outbound.chat_max_attempts' => 2,
        ]);
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $this->app->make(RedisManager::class)->connection()->command('set', [
            $prefix.'outbound:global',
            '1',
        ]);

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramSharedRateLimiter::class)->reserveOutbound(901004);
    }

    public function test_corrupt_outbound_chat_bucket_without_expiry_below_limit_fails_closed(): void
    {
        config([
            'telegram.rate_limits.outbound.global_max_attempts' => 3,
            'telegram.rate_limits.outbound.chat_max_attempts' => 2,
        ]);
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $chatId = 901005;
        $key = $prefix.'outbound:chat:'.hash('sha256', (string) $chatId);
        $this->app->make(RedisManager::class)->connection()->command('set', [$key, '1']);

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramSharedRateLimiter::class)->reserveOutbound($chatId);
    }

    public function test_positive_non_integer_outbound_global_bucket_fails_closed_without_mutation(): void
    {
        config([
            'telegram.rate_limits.outbound.global_max_attempts' => 3,
            'telegram.rate_limits.outbound.chat_max_attempts' => 2,
        ]);
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $chatId = 901006;
        $globalKey = $prefix.'outbound:global';
        $chatKey = $prefix.'outbound:chat:'.hash('sha256', (string) $chatId);
        $redis = $this->app->make(RedisManager::class)->connection();
        $redis->command('set', [$globalKey, '1.5']);
        $redis->command('expire', [$globalKey, '60']);
        $globalExpiresAt = $redis->command('pexpiretime', [$globalKey]);

        try {
            $this->app->make(TelegramSharedRateLimiter::class)->reserveOutbound($chatId);
            self::fail('Redis-INCR-incompatible global outbound state must fail closed.');
        } catch (RuntimeException) {
            self::assertSame('1.5', $redis->command('get', [$globalKey]));
            self::assertSame($globalExpiresAt, $redis->command('pexpiretime', [$globalKey]));
            self::assertNull($redis->command('get', [$chatKey]));
        }
    }

    public function test_positive_non_integer_outbound_chat_bucket_cannot_partially_consume_global_budget(): void
    {
        config([
            'telegram.rate_limits.outbound.global_max_attempts' => 3,
            'telegram.rate_limits.outbound.chat_max_attempts' => 2,
        ]);
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $chatId = 901007;
        $globalKey = $prefix.'outbound:global';
        $chatKey = $prefix.'outbound:chat:'.hash('sha256', (string) $chatId);
        $redis = $this->app->make(RedisManager::class)->connection();
        $redis->command('set', [$globalKey, '1']);
        $redis->command('expire', [$globalKey, '60']);
        $redis->command('set', [$chatKey, '1.5']);
        $redis->command('expire', [$chatKey, '60']);
        $globalExpiresAt = $redis->command('pexpiretime', [$globalKey]);
        $chatExpiresAt = $redis->command('pexpiretime', [$chatKey]);

        try {
            $this->app->make(TelegramSharedRateLimiter::class)->reserveOutbound($chatId);
            self::fail('Corrupt chat state must fail before either outbound budget is mutated.');
        } catch (RuntimeException) {
            self::assertSame('1', $redis->command('get', [$globalKey]));
            self::assertSame('1.5', $redis->command('get', [$chatKey]));
            self::assertSame($globalExpiresAt, $redis->command('pexpiretime', [$globalKey]));
            self::assertSame($chatExpiresAt, $redis->command('pexpiretime', [$chatKey]));
        }
    }
}
