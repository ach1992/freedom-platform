<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\Contracts\OtpAbuseLimiter;
use App\Modules\Identity\Application\Exceptions\InvalidOtpCode;
use App\Modules\Identity\Application\Exceptions\OtpChallengeInactive;
use App\Modules\Identity\Application\Exceptions\OtpResendCooldownActive;
use App\Modules\Identity\Application\FallbackSmsDispatcher;
use App\Modules\Identity\Application\OtpChallengeIssuer;
use App\Modules\Identity\Application\OtpChallengeVerifier;
use App\Modules\Identity\Application\OtpIssueRequest;
use App\Modules\Identity\Application\OtpRateLimitBucket;
use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use App\Modules\Identity\Infrastructure\DatabaseSmsDeliveryAttemptRecorder;
use App\Modules\Identity\Infrastructure\FakeSmsProvider;
use App\Modules\Identity\Infrastructure\HmacOtpCodeHasher;
use App\Modules\Identity\Infrastructure\HmacPhoneLookupHasher;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use Database\Seeders\IdentityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement ONB-004 ONB-005 SEC-003 DAT-003 INT-002 */
final class OtpChallengeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_is_idempotent_and_successful_verification_consumes_one_hashed_code(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        [$userId, $telegramAccountId] = $this->identity(921000001, 1001);
        $clock = new MutableOtpClock(new DateTimeImmutable('2026-08-05T00:00:00+00:00'));
        $primary = new FakeSmsProvider('primary', [SmsDeliveryResult::accepted('provider-message')]);
        [$issuer, $verifier] = $this->services($clock, $primary);
        $request = $this->request($userId, $telegramAccountId, 'otp-feature-idempotency-0001');

        $first = $issuer->issue($request);
        $duplicate = $issuer->issue($request);

        $this->assertSame($first->challengeId, $duplicate->challengeId);
        $this->assertSame(1, $primary->sentCount());

        /** @var object{code_hash: string, request_ip_hash: string, request_idempotency_hash: string, delivery_status: string}|null $challenge */
        $challenge = DB::table('otp_challenges')->where('id', $first->challengeId)->first();
        $this->assertNotNull($challenge);
        $this->assertNotSame('123456', $challenge->code_hash);
        $this->assertSame(64, strlen($challenge->code_hash));
        $this->assertSame(64, strlen($challenge->request_ip_hash));
        $this->assertSame(64, strlen($challenge->request_idempotency_hash));
        $this->assertSame('accepted', $challenge->delivery_status);
        $this->assertStringNotContainsString(
            '123456',
            json_encode(DB::table('otp_challenges')->where('id', $first->challengeId)->first(), JSON_THROW_ON_ERROR),
        );

        try {
            $verifier->verify($userId, $first->challengeId, '000000', 'otp-verify-wrong-0001');
            $this->fail('An incorrect OTP code must fail.');
        } catch (InvalidOtpCode $exception) {
            $this->assertSame(4, $exception->remainingAttempts);
        }

        $verified = $verifier->verify($userId, $first->challengeId, '123456', 'otp-verify-ok-0001');
        $this->assertTrue($verified->policySatisfied);
        $this->assertDatabaseHas('otp_challenges', [
            'id' => $first->challengeId,
            'attempt_count' => 1,
            'active_scope_hash' => null,
        ]);
        $this->assertNotNull(DB::table('otp_challenges')->where('id', $first->challengeId)->value('consumed_at'));
        $this->assertDatabaseHas('phone_verification_evidences', [
            'phone_number_id' => $first->phoneNumberId,
            'method' => 'sms_otp',
        ]);
        $this->assertDatabaseHas('phone_numbers', [
            'id' => $first->phoneNumberId,
            'status' => 'verified',
            'last_verification_method' => 'sms_otp',
        ]);
        $this->assertDatabaseHas('customer_profiles', [
            'user_id' => $userId,
            'phone_verification_status' => 'verified',
        ]);

        $this->expectException(OtpChallengeInactive::class);
        $verifier->verify($userId, $first->challengeId, '123456');
    }

    public function test_resend_cooldown_blocks_a_new_request_and_later_invalidates_the_old_challenge(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        [$userId, $telegramAccountId] = $this->identity(921000002, 1001);
        $clock = new MutableOtpClock(new DateTimeImmutable('2026-08-05T00:00:00+00:00'));
        $primary = new FakeSmsProvider('primary');
        [$issuer] = $this->services($clock, $primary);
        $first = $issuer->issue($this->request($userId, $telegramAccountId, 'otp-feature-cooldown-0001'));

        try {
            $issuer->issue($this->request($userId, $telegramAccountId, 'otp-feature-cooldown-0002'));
            $this->fail('A resend inside the cooldown must fail.');
        } catch (OtpResendCooldownActive) {
            $this->assertSame(1, $primary->sentCount());
        }

        $clock->advanceSeconds(61);
        $second = $issuer->issue($this->request($userId, $telegramAccountId, 'otp-feature-cooldown-0003'));
        $this->assertNotSame($first->challengeId, $second->challengeId);
        $this->assertSame(2, $primary->sentCount());
        $this->assertNotNull(DB::table('otp_challenges')->where('id', $first->challengeId)->value('invalidated_at'));
        $this->assertDatabaseHas('otp_challenges', [
            'id' => $first->challengeId,
            'active_scope_hash' => null,
        ]);
    }

    public function test_five_incorrect_attempts_lock_the_challenge(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        [$userId, $telegramAccountId] = $this->identity(921000003, 1001);
        $clock = new MutableOtpClock(new DateTimeImmutable('2026-08-05T00:00:00+00:00'));
        [$issuer, $verifier] = $this->services($clock, new FakeSmsProvider('primary'));
        $challenge = $issuer->issue($this->request($userId, $telegramAccountId, 'otp-feature-attempts-0001'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $verifier->verify($userId, $challenge->challengeId, '000000');
                $this->fail('Incorrect OTP must fail.');
            } catch (InvalidOtpCode $exception) {
                $this->assertSame(5 - $attempt, $exception->remainingAttempts);
            }
        }

        $this->assertDatabaseHas('otp_challenges', [
            'id' => $challenge->challengeId,
            'attempt_count' => 5,
            'active_scope_hash' => null,
        ]);
        $this->assertNotNull(DB::table('otp_challenges')->where('id', $challenge->challengeId)->value('invalidated_at'));
        $this->assertDatabaseHas('phone_verification_events', [
            'phone_number_id' => $challenge->phoneNumberId,
            'event_type' => 'otp_locked',
        ]);
    }

    /** @return array{OtpChallengeIssuer, OtpChallengeVerifier} */
    private function services(MutableOtpClock $clock, FakeSmsProvider $primary): array
    {
        $database = $this->app->make(DatabaseManager::class);
        $phoneHasher = new HmacPhoneLookupHasher(str_repeat('p', 32), 1);
        $otpHasher = new HmacOtpCodeHasher(str_repeat('o', 32), 1);
        $recorder = new DatabaseSmsDeliveryAttemptRecorder(
            $database,
            $this->app->make(StringEncrypter::class),
            $phoneHasher,
            $clock,
        );
        $dispatcher = new FallbackSmsDispatcher(
            $primary,
            new FakeSmsProvider('fallback'),
            $recorder,
        );
        $issuer = new OtpChallengeIssuer(
            $database,
            $this->app->make(StringEncrypter::class),
            $phoneHasher,
            $otpHasher,
            new AllowAllOtpLimiter,
            $dispatcher,
            new FixedOtpRandomGenerator,
            $clock,
        );

        return [$issuer, new OtpChallengeVerifier($database, $otpHasher, $clock)];
    }

    private function request(int $userId, int $telegramAccountId, string $idempotencyKey): OtpIssueRequest
    {
        return new OtpIssueRequest(
            $userId,
            $telegramAccountId,
            IranianMobileNumber::fromString('09123456789'),
            PhoneVerificationPolicy::SmsOtpOnly,
            1,
            'phone_verification',
            '203.0.113.10',
            $idempotencyKey,
            'otp-correlation-0001',
        );
    }

    /** @return array{int, int} */
    private function identity(int $telegramUserId, int $botId): array
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $telegramAccountId = (int) DB::table('telegram_accounts')->insertGetId([
            'user_id' => $userId,
            'bot_id' => $botId,
            'telegram_user_id' => $telegramUserId,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$userId, $telegramAccountId];
    }
}

final class MutableOtpClock implements Clock
{
    public function __construct(private DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->time = $this->time->modify('+'.$seconds.' seconds');
    }
}

final class FixedOtpRandomGenerator implements RandomGenerator
{
    public function bytes(int $length): string
    {
        return str_repeat('x', $length);
    }

    public function integer(int $minimum, int $maximum): int
    {
        return 123456;
    }
}

final class AllowAllOtpLimiter implements OtpAbuseLimiter
{
    public function consume(array $buckets): void
    {
        foreach ($buckets as $bucket) {
            if (! $bucket instanceof OtpRateLimitBucket) {
                throw new \LogicException('Unexpected OTP bucket.');
            }
        }
    }
}
