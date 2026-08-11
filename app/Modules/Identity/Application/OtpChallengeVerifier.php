<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Contracts\OtpCodeHasher;
use App\Modules\Identity\Application\Exceptions\InvalidOtpCode;
use App\Modules\Identity\Application\Exceptions\OtpChallengeExpired;
use App\Modules\Identity\Application\Exceptions\OtpChallengeInactive;
use App\Modules\Identity\Application\Exceptions\OtpChallengeNotFound;
use App\Modules\Identity\Domain\PhoneVerificationMethod;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class OtpChallengeVerifier
{
    public function __construct(
        private DatabaseManager $database,
        private OtpCodeHasher $hasher,
        private Clock $clock,
    ) {}

    /** @requirement ONB-004 ONB-005 SEC-003 DAT-003 */
    public function verify(
        int $userId,
        string $challengeId,
        string $code,
        ?string $correlationId = null,
    ): OtpVerificationReceipt {
        $this->assertInput($userId, $challengeId, $code, $correlationId);

        $outcome = $this->database->connection()->transaction(
            fn (): OtpVerificationOutcome => $this->verifyWithinTransaction(
                $userId,
                $challengeId,
                $code,
                $correlationId,
            ),
        );

        if ($outcome->receipt !== null) {
            return $outcome->receipt;
        }

        if ($outcome->failure === 'expired') {
            throw new OtpChallengeExpired;
        }

        if ($outcome->failure === 'invalid_code' && $outcome->remainingAttempts !== null) {
            throw new InvalidOtpCode($outcome->remainingAttempts);
        }

        throw new OtpChallengeInactive;
    }

    private function assertInput(
        int $userId,
        string $challengeId,
        string $code,
        ?string $correlationId,
    ): void {
        if ($userId < 1 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $challengeId) !== 1) {
            throw new InvalidArgumentException('OTP verification identity is invalid.');
        }

        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            throw new InvalidArgumentException('OTP code must contain exactly six ASCII digits.');
        }

        if ($correlationId !== null && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('OTP correlation ID is invalid.');
        }
    }

    private function verifyWithinTransaction(
        int $userId,
        string $challengeId,
        string $code,
        ?string $correlationId,
    ): OtpVerificationOutcome {
        $connection = $this->database->connection();
        $now = $this->clock->now();
        $nowString = $now->format('Y-m-d H:i:s.u');

        /** @var object{phone_number_id: int|string}|null $challengePhone */
        $challengePhone = $connection->table('otp_challenges')
            ->where('id', $challengeId)
            ->where('user_id', $userId)
            ->first(['phone_number_id']);

        if ($challengePhone === null) {
            throw new OtpChallengeNotFound;
        }

        $phoneNumberId = (int) $challengePhone->phone_number_id;

        // Lock phone rows before OTP rows to match Telegram contact re-binding.
        /** @var object{active_user_id: int|string|null, active_lookup_hash: ?string}|null $phone */
        $phone = $connection->table('phone_numbers')
            ->where('id', $phoneNumberId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first(['active_user_id', 'active_lookup_hash']);

        /** @var object{id: string, phone_number_id: int|string, destination_lookup_hash: string, code_hash: string, attempt_count: int|string, maximum_attempts: int|string, expires_at: string, consumed_at: ?string, invalidated_at: ?string, verification_policy: string, verification_policy_version: int|string, telegram_account_id: int|string}|null $challenge */
        $challenge = $connection->table('otp_challenges')
            ->where('id', $challengeId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first([
                'id',
                'phone_number_id',
                'destination_lookup_hash',
                'code_hash',
                'attempt_count',
                'maximum_attempts',
                'expires_at',
                'consumed_at',
                'invalidated_at',
                'verification_policy',
                'verification_policy_version',
                'telegram_account_id',
            ]);

        if ($challenge === null) {
            throw new OtpChallengeNotFound;
        }

        if ($challenge->consumed_at !== null || $challenge->invalidated_at !== null) {
            throw new OtpChallengeInactive;
        }

        if (
            $phone === null
            || (int) $phone->active_user_id !== $userId
            || $phone->active_lookup_hash !== $challenge->destination_lookup_hash
        ) {
            $connection->table('otp_challenges')->where('id', $challengeId)->update([
                'invalidated_at' => $nowString,
                'active_scope_hash' => null,
                'updated_at' => $nowString,
            ]);

            return OtpVerificationOutcome::inactive();
        }

        $phoneNumberId = (int) $challenge->phone_number_id;
        $policyVersion = (int) $challenge->verification_policy_version;
        $telegramAccountId = (int) $challenge->telegram_account_id;
        $expiresAt = new DateTimeImmutable($challenge->expires_at, new DateTimeZone('UTC'));

        if ($expiresAt <= $now) {
            $connection->table('otp_challenges')->where('id', $challengeId)->update([
                'invalidated_at' => $nowString,
                'active_scope_hash' => null,
                'updated_at' => $nowString,
            ]);
            $this->recordEvent(
                $userId,
                $phoneNumberId,
                'otp_expired',
                $policyVersion,
                $telegramAccountId,
                $correlationId,
                $nowString,
            );

            return OtpVerificationOutcome::expired();
        }

        $attempts = (int) $challenge->attempt_count;
        $maximumAttempts = (int) $challenge->maximum_attempts;

        if ($attempts >= $maximumAttempts) {
            throw new OtpChallengeInactive;
        }

        if (! $this->hasher->verify($challengeId, $code, $challenge->code_hash)) {
            $attempts++;
            $remaining = max(0, $maximumAttempts - $attempts);
            $updates = ['attempt_count' => $attempts, 'updated_at' => $nowString];

            if ($remaining === 0) {
                $updates['invalidated_at'] = $nowString;
                $updates['active_scope_hash'] = null;
            }

            $connection->table('otp_challenges')->where('id', $challengeId)->update($updates);
            $this->recordEvent(
                $userId,
                $phoneNumberId,
                $remaining === 0 ? 'otp_locked' : 'otp_failed',
                $policyVersion,
                $telegramAccountId,
                $correlationId,
                $nowString,
            );

            return OtpVerificationOutcome::invalidCode($remaining);
        }

        $policy = PhoneVerificationPolicy::from($challenge->verification_policy);
        $connection->table('otp_challenges')->where('id', $challengeId)->update([
            'consumed_at' => $nowString,
            'active_scope_hash' => null,
            'updated_at' => $nowString,
        ]);
        $connection->table('otp_challenges')
            ->where('phone_number_id', $phoneNumberId)
            ->where('id', '<>', $challengeId)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => $nowString, 'active_scope_hash' => null, 'updated_at' => $nowString]);

        $this->recordOtpEvidence($phoneNumberId, $policyVersion, $nowString);
        $methods = $connection->table('phone_verification_evidences')
            ->where('phone_number_id', $phoneNumberId)
            ->whereNull('invalidated_at')
            ->pluck('method')
            ->all();
        $policySatisfied = $policy->isSatisfied(
            in_array(PhoneVerificationMethod::TelegramContact->value, $methods, true),
            in_array(PhoneVerificationMethod::SmsOtp->value, $methods, true),
        );
        $status = $policySatisfied ? 'verified' : 'pending';

        $connection->table('phone_numbers')->where('id', $phoneNumberId)->update([
            'status' => $status,
            'last_verification_method' => PhoneVerificationMethod::SmsOtp->value,
            'verification_policy' => $policy->value,
            'verification_policy_version' => $policyVersion,
            'verified_at' => $policySatisfied ? $nowString : null,
            'updated_at' => $nowString,
        ]);
        $connection->table('customer_profiles')->where('user_id', $userId)->update([
            'phone_verification_status' => $status,
            'updated_at' => $nowString,
        ]);
        $this->recordEvent(
            $userId,
            $phoneNumberId,
            'otp_verified',
            $policyVersion,
            $telegramAccountId,
            $correlationId,
            $nowString,
            $status,
        );

        return OtpVerificationOutcome::verified(new OtpVerificationReceipt(
            $challengeId,
            $phoneNumberId,
            $policy,
            $policyVersion,
            $policySatisfied,
        ));
    }

    private function recordOtpEvidence(int $phoneNumberId, int $policyVersion, string $now): void
    {
        $connection = $this->database->connection();
        $connection->table('phone_verification_evidences')->insertOrIgnore([
            'phone_number_id' => $phoneNumberId,
            'method' => PhoneVerificationMethod::SmsOtp->value,
            'policy_version' => $policyVersion,
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->table('phone_verification_evidences')
            ->where('phone_number_id', $phoneNumberId)
            ->where('method', PhoneVerificationMethod::SmsOtp->value)
            ->update([
                'policy_version' => $policyVersion,
                'verified_at' => $now,
                'invalidated_at' => null,
                'updated_at' => $now,
            ]);
    }

    private function recordEvent(
        int $userId,
        int $phoneNumberId,
        string $eventType,
        int $policyVersion,
        int $telegramAccountId,
        ?string $correlationId,
        string $createdAt,
        string $toStatus = 'pending',
    ): void {
        $this->database->connection()->table('phone_verification_events')->insert([
            'user_id' => $userId,
            'phone_number_id' => $phoneNumberId,
            'event_type' => $eventType,
            'from_status' => null,
            'to_status' => $toStatus,
            'method' => PhoneVerificationMethod::SmsOtp->value,
            'policy_version' => $policyVersion,
            'telegram_account_id' => $telegramAccountId,
            'reason_code' => null,
            'correlation_id' => $correlationId,
            'created_at' => $createdAt,
        ]);
    }
}
