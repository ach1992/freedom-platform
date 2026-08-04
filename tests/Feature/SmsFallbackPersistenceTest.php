<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\FallbackSmsDispatcher;
use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Application\SmsOtpMessage;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Infrastructure\DatabaseSmsDeliveryAttemptRecorder;
use App\Modules\Identity\Infrastructure\FakeSmsProvider;
use App\Modules\Identity\Infrastructure\HmacPhoneLookupHasher;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement ONB-004 SEC-003 DAT-003 INT-002 */
final class SmsFallbackPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitive_failure_uses_fallback_and_persists_only_encrypted_or_hashed_evidence(): void
    {
        $userId = $this->user();
        $hasher = new HmacPhoneLookupHasher(str_repeat('s', 32), 2);
        $recorder = new DatabaseSmsDeliveryAttemptRecorder(
            $this->app->make(DatabaseManager::class),
            $this->app->make(StringEncrypter::class),
            $hasher,
            $this->app->make(Clock::class),
        );
        $dispatcher = new FallbackSmsDispatcher(
            new FakeSmsProvider('melli_fake', [SmsDeliveryResult::definitiveFailure('rejected')]),
            new FakeSmsProvider('kavenegar_fake', [SmsDeliveryResult::accepted('provider-message-123')]),
            $recorder,
        );
        $message = new SmsOtpMessage(
            IranianMobileNumber::fromString('09123456789'),
            '654321',
            'phone_verification',
            'phone-verification:feature:0001',
            $userId,
        );

        $result = $dispatcher->dispatch($message);
        $this->assertCount(2, $result->attempts);

        /** @var list<object{provider_code: string, outcome: string, destination_lookup_hash: string, idempotency_key_hash: string, provider_message_id_ciphertext: ?string}> $rows */
        $rows = DB::table('sms_delivery_attempts')->orderBy('attempt_number')->get()->all();
        $this->assertCount(2, $rows);
        $this->assertSame('melli_fake', $rows[0]->provider_code);
        $this->assertSame('definitive_failure', $rows[0]->outcome);
        $this->assertSame('kavenegar_fake', $rows[1]->provider_code);
        $this->assertSame('accepted', $rows[1]->outcome);
        $this->assertSame(64, strlen($rows[1]->destination_lookup_hash));
        $this->assertSame(64, strlen($rows[1]->idempotency_key_hash));
        $this->assertNotNull($rows[1]->provider_message_id_ciphertext);
        $this->assertNotSame('provider-message-123', $rows[1]->provider_message_id_ciphertext);
        $this->assertSame(
            'provider-message-123',
            $this->app->make(StringEncrypter::class)->decryptString($rows[1]->provider_message_id_ciphertext),
        );
        $this->assertStringNotContainsString('654321', json_encode($rows, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('09123456789', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
