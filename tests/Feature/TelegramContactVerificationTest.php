<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\Contracts\CustomerIdentityProfileWriter;
use App\Modules\Identity\Application\Exceptions\PhoneAlreadyAssigned;
use App\Modules\Identity\Application\Exceptions\PhoneVerificationMethodNotAllowed;
use App\Modules\Identity\Application\Exceptions\TelegramContactOwnershipMismatch;
use App\Modules\Identity\Application\TelegramContactVerifier;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use App\Modules\Identity\Infrastructure\HmacPhoneLookupHasher;
use App\Shared\Application\Clock;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement ONB-004 ONB-005 USR-001 SEC-003 DAT-003 */
final class TelegramContactVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_verification_encrypts_and_binds_a_canonical_number_with_append_only_evidence(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        [$userId] = $this->identity(912345001, 1001);

        $receipt = $this->verifier()->verify(
            $userId,
            '1001',
            912345001,
            ['user_id' => 912345001, 'phone_number' => '۰۹۱۲ ۳۴۵-۶۷۸۹'],
            PhoneVerificationPolicy::TelegramContactOnly,
            3,
            'contact-test-0001',
        );

        $this->assertSame('+989123456789', $receipt->number->e164());
        $this->assertTrue($receipt->policySatisfied);

        /** @var object{encrypted_value: string, lookup_hash: string}|null $phone */
        $phone = DB::table('phone_numbers')->where('id', $receipt->phoneNumberId)->first();
        $this->assertNotNull($phone);
        $this->assertNotSame('+989123456789', $phone->encrypted_value);
        $this->assertSame(
            '+989123456789',
            $this->app->make(StringEncrypter::class)->decryptString($phone->encrypted_value),
        );
        $this->assertSame(64, strlen($phone->lookup_hash));
        $this->assertDatabaseHas('phone_numbers', [
            'id' => $receipt->phoneNumberId,
            'user_id' => $userId,
            'active_user_id' => $userId,
            'status' => 'verified',
            'verification_policy' => 'telegram_contact_only',
            'verification_policy_version' => 3,
            'last_verification_method' => 'telegram_contact',
        ]);
        $this->assertDatabaseHas('phone_verification_evidences', [
            'phone_number_id' => $receipt->phoneNumberId,
            'method' => 'telegram_contact',
            'policy_version' => 3,
        ]);
        $this->assertDatabaseHas('phone_verification_events', [
            'user_id' => $userId,
            'phone_number_id' => $receipt->phoneNumberId,
            'event_type' => 'contact_verified',
            'to_status' => 'verified',
            'correlation_id' => 'contact-test-0001',
        ]);
        $this->assertDatabaseHas('customer_profiles', [
            'user_id' => $userId,
            'phone_verification_status' => 'verified',
        ]);
    }

    public function test_contact_owner_must_match_the_sender_and_policy_must_allow_contact(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        [$userId] = $this->identity(912345002, 1001);

        try {
            $this->verifier()->verify(
                $userId,
                '1001',
                912345002,
                ['user_id' => 912345999, 'phone_number' => '09123456789'],
                PhoneVerificationPolicy::TelegramContactOnly,
                1,
            );
            $this->fail('A foreign Telegram contact must be rejected.');
        } catch (TelegramContactOwnershipMismatch) {
            $this->assertDatabaseCount('phone_numbers', 0);
        }

        $this->expectException(PhoneVerificationMethodNotAllowed::class);
        $this->verifier()->verify(
            $userId,
            '1001',
            912345002,
            ['user_id' => 912345002, 'phone_number' => '09123456789'],
            PhoneVerificationPolicy::SmsOtpOnly,
            1,
        );
    }

    public function test_policy_both_reserves_the_number_but_remains_pending_until_otp_evidence_exists(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        [$userId] = $this->identity(912345003, 1001);

        $receipt = $this->verifier()->verify(
            $userId,
            '1001',
            912345003,
            ['user_id' => 912345003, 'phone_number' => '09123456789'],
            PhoneVerificationPolicy::Both,
            2,
        );

        $this->assertFalse($receipt->policySatisfied);
        $this->assertDatabaseHas('phone_numbers', [
            'id' => $receipt->phoneNumberId,
            'active_user_id' => $userId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('customer_profiles', [
            'user_id' => $userId,
            'phone_verification_status' => 'pending',
        ]);
    }

    public function test_active_number_is_globally_unique_and_changing_number_releases_previous_evidence(): void
    {
        $this->seed(IdentityAccessFoundationSeeder::class);
        [$firstUser] = $this->identity(912345004, 1001);
        [$secondUser] = $this->identity(912345005, 1001);
        $verifier = $this->verifier();

        $first = $verifier->verify(
            $firstUser,
            '1001',
            912345004,
            ['user_id' => 912345004, 'phone_number' => '09123456789'],
            PhoneVerificationPolicy::Either,
            1,
        );

        try {
            $verifier->verify(
                $secondUser,
                '1001',
                912345005,
                ['user_id' => 912345005, 'phone_number' => '09123456789'],
                PhoneVerificationPolicy::Either,
                1,
            );
            $this->fail('An active number must not be assigned to a second customer.');
        } catch (PhoneAlreadyAssigned) {
            $this->assertSame(1, DB::table('phone_numbers')->whereNotNull('active_lookup_hash')->count());
        }

        $second = $verifier->verify(
            $firstUser,
            '1001',
            912345004,
            ['user_id' => 912345004, 'phone_number' => '09351234567'],
            PhoneVerificationPolicy::Either,
            2,
        );

        $this->assertNotSame($first->phoneNumberId, $second->phoneNumberId);
        $this->assertDatabaseHas('phone_numbers', [
            'id' => $first->phoneNumberId,
            'status' => 'released',
            'active_user_id' => null,
            'active_lookup_hash' => null,
        ]);
        $this->assertDatabaseHas('phone_verification_events', [
            'phone_number_id' => $first->phoneNumberId,
            'event_type' => 'released',
            'reason_code' => 'number_changed',
        ]);
        $this->assertSame(
            1,
            DB::table('phone_verification_evidences')
                ->where('phone_number_id', $first->phoneNumberId)
                ->whereNotNull('invalidated_at')
                ->count(),
        );
        $this->assertSame(1, DB::table('phone_numbers')->where('active_user_id', $firstUser)->count());
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
        $accountId = (int) DB::table('telegram_accounts')->insertGetId([
            'user_id' => $userId,
            'bot_id' => $botId,
            'telegram_user_id' => $telegramUserId,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$userId, $accountId];
    }

    private function verifier(): TelegramContactVerifier
    {
        return new TelegramContactVerifier(
            $this->app->make(DatabaseManager::class),
            $this->app->make(StringEncrypter::class),
            new HmacPhoneLookupHasher(str_repeat('k', 32), 1),
            $this->app->make(CustomerIdentityProfileWriter::class),
            $this->app->make(Clock::class),
        );
    }
}
