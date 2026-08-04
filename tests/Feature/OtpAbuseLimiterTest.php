<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\Exceptions\OtpRateLimitExceeded;
use App\Modules\Identity\Application\OtpRateLimitBucket;
use App\Modules\Identity\Application\SmsOtpMessage;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Infrastructure\FakeSmsProvider;
use App\Modules\Identity\Infrastructure\RateLimitedSmsProvider;
use App\Modules\Identity\Infrastructure\RedisOtpAbuseLimiter;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement ONB-004 SEC-003 INT-002 */
final class OtpAbuseLimiterTest extends TestCase
{
    public function test_multi_bucket_consumption_is_atomic_and_fails_closed(): void
    {
        $limiter = $this->limiter();
        $phone = new OtpRateLimitBucket('phone', 'phone-a', 1, 60);
        $account = new OtpRateLimitBucket('telegram_account', 'account-a', 2, 60);

        $limiter->consume([$phone, $account]);

        try {
            $limiter->consume([$phone, $account]);
            $this->fail('Exhausted phone bucket must block the complete atomic consume.');
        } catch (OtpRateLimitExceeded $exception) {
            $this->assertSame('phone', $exception->bucketName);
        }

        $limiter->consume([$account]);

        $this->expectException(OtpRateLimitExceeded::class);
        $limiter->consume([$account]);
    }

    public function test_provider_decorator_enforces_provider_daily_limit_before_send(): void
    {
        $provider = new FakeSmsProvider('limited_provider');
        $limited = new RateLimitedSmsProvider($provider, $this->limiter(), 1);
        $message = new SmsOtpMessage(
            IranianMobileNumber::fromString('09123456789'),
            '123456',
            'phone_verification',
            'provider-limit-feature-0001',
        );

        $limited->sendOtp($message);
        $this->assertSame(1, $provider->sentCount());

        try {
            $limited->sendOtp($message);
            $this->fail('Provider limit must block a second send.');
        } catch (OtpRateLimitExceeded $exception) {
            $this->assertSame('provider', $exception->bucketName);
            $this->assertSame(1, $provider->sentCount());
        }
    }

    private function limiter(): RedisOtpAbuseLimiter
    {
        return new RedisOtpAbuseLimiter(
            $this->app->make(RedisManager::class),
            'test:otp-limit:'.Str::uuid().':',
        );
    }
}
