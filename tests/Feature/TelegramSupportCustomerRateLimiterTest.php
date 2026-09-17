<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramSupportCustomerRateLimiter;
use App\Modules\Telegram\Application\TelegramSupportCustomerRateLimitScope;
use RuntimeException;
use Tests\TestCase;

/** @requirement SUP-002 SEC-003 QUA-004 */
final class TelegramSupportCustomerRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'support.rate_limits.prefix' => 'test:telegram-support-rate-limit:'.bin2hex(random_bytes(8)).':',
            'support.rate_limits.ticket_creation.max_attempts' => 2,
            'support.rate_limits.ticket_creation.window_seconds' => 60,
            'support.rate_limits.customer_content.max_attempts' => 1,
            'support.rate_limits.customer_content.window_seconds' => 60,
        ]);
    }

    public function test_scopes_are_atomic_bounded_and_isolated_per_user(): void
    {
        $limiter = $this->app->make(TelegramSupportCustomerRateLimiter::class);
        $userId = random_int(1_000_000, 2_000_000_000);

        self::assertTrue($limiter->consume($userId, TelegramSupportCustomerRateLimitScope::TicketCreation)->allowed);
        self::assertTrue($limiter->consume($userId, TelegramSupportCustomerRateLimitScope::TicketCreation)->allowed);
        $limited = $limiter->consume($userId, TelegramSupportCustomerRateLimitScope::TicketCreation);
        self::assertFalse($limited->allowed);
        self::assertNotNull($limited->retryAfterSeconds);
        self::assertGreaterThanOrEqual(1, $limited->retryAfterSeconds);
        self::assertLessThanOrEqual(60, $limited->retryAfterSeconds);

        self::assertTrue($limiter->consume($userId, TelegramSupportCustomerRateLimitScope::CustomerContent)->allowed);
        self::assertFalse($limiter->consume($userId, TelegramSupportCustomerRateLimitScope::CustomerContent)->allowed);

        self::assertTrue($limiter->consume($userId + 1, TelegramSupportCustomerRateLimitScope::TicketCreation)->allowed);
    }

    public function test_corrupt_bucket_without_expiry_fails_closed(): void
    {
        config(['support.rate_limits.customer_content.max_attempts' => 1]);
        $userId = random_int(1_000_000, 2_000_000_000);
        $prefix = config('support.rate_limits.prefix');
        self::assertIsString($prefix);
        $key = $prefix.hash('sha256', TelegramSupportCustomerRateLimitScope::CustomerContent->value."\0".$userId);
        $this->app->make(\Illuminate\Redis\RedisManager::class)->connection()->command('set', [$key, '1']);

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramSupportCustomerRateLimiter::class)->consume(
            $userId,
            TelegramSupportCustomerRateLimitScope::CustomerContent,
        );
    }

    public function test_invalid_configuration_fails_closed_before_consumption(): void
    {
        config(['support.rate_limits.ticket_creation.max_attempts' => 0]);

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramSupportCustomerRateLimiter::class)->consume(
            random_int(1_000_000, 2_000_000_000),
            TelegramSupportCustomerRateLimitScope::TicketCreation,
        );
    }
}
