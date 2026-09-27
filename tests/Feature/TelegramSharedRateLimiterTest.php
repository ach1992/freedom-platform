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

    public function test_corrupt_interaction_bucket_without_expiry_fails_closed(): void
    {
        $prefix = config('telegram.rate_limits.prefix');
        self::assertIsString($prefix);
        $userId = random_int(1_000_000, 2_000_000_000);
        $key = $prefix.'interaction:'.hash('sha256', (string) $userId);
        $this->app->make(RedisManager::class)->connection()->command('set', [$key, '2']);

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramSharedRateLimiter::class)->consumeInteraction($userId);
    }
}
