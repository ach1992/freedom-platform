<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Modules\Identity\Application\Exceptions\OtpRateLimitExceeded;
use App\Modules\Identity\Application\FallbackSmsDispatcher;
use App\Modules\Identity\Application\OtpRateLimitBucket;
use App\Modules\Identity\Application\SmsDeliveryAttempt;
use App\Modules\Identity\Application\SmsOtpMessage;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\SmsDeliveryStatus;
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

    public function test_provider_daily_limit_returns_definitive_failure_before_send(): void
    {
        $provider = new FakeSmsProvider('limited_provider');
        $limited = new RateLimitedSmsProvider($provider, $this->limiter(), 1);
        $message = $this->message();

        $this->assertSame(SmsDeliveryStatus::Accepted, $limited->sendOtp($message)->status);
        $this->assertSame(1, $provider->sentCount());

        $blocked = $limited->sendOtp($message);
        $this->assertSame(SmsDeliveryStatus::DefinitiveFailure, $blocked->status);
        $this->assertSame('provider_rate_limit', $blocked->errorCode);
        $this->assertSame(1, $provider->sentCount());
    }

    public function test_primary_provider_quota_failure_safely_falls_back_without_a_primary_send(): void
    {
        $limiter = $this->limiter();
        $primary = new FakeSmsProvider('quota_primary');
        $fallback = new FakeSmsProvider('quota_fallback');
        $limitedPrimary = new RateLimitedSmsProvider($primary, $limiter, 1);
        $recorder = new CollectingOtpAbuseAttemptRecorder;
        $message = $this->message();

        $limitedPrimary->sendOtp($message);
        $result = (new FallbackSmsDispatcher($limitedPrimary, $fallback, $recorder))->dispatch($message);

        $this->assertCount(2, $result->attempts);
        $this->assertSame(SmsDeliveryStatus::DefinitiveFailure, $result->attempts[0]->result->status);
        $this->assertSame(SmsDeliveryStatus::Accepted, $result->finalAttempt()->result->status);
        $this->assertSame(1, $primary->sentCount());
        $this->assertSame(1, $fallback->sentCount());
        $this->assertCount(2, $recorder->attempts);
    }

    private function message(): SmsOtpMessage
    {
        return new SmsOtpMessage(
            IranianMobileNumber::fromString('09123456789'),
            '123456',
            'phone_verification',
            'provider-limit-feature-0001',
        );
    }

    private function limiter(): RedisOtpAbuseLimiter
    {
        return new RedisOtpAbuseLimiter(
            $this->app->make(RedisManager::class),
            'test:otp-limit:'.Str::uuid().':',
        );
    }
}

final class CollectingOtpAbuseAttemptRecorder implements SmsDeliveryAttemptRecorder
{
    /** @var list<SmsDeliveryAttempt> */
    public array $attempts = [];

    public function record(SmsOtpMessage $message, SmsDeliveryAttempt $attempt, int $sequence): void
    {
        $this->attempts[] = $attempt;
    }
}
