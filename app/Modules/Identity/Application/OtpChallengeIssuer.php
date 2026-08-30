<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Contracts\CustomerIdentityProfileWriter;
use App\Modules\Identity\Application\Contracts\OtpAbuseLimiter;
use App\Modules\Identity\Application\Contracts\OtpCodeHasher;
use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Application\Exceptions\OtpResendCooldownActive;
use App\Modules\Identity\Application\Exceptions\PhoneAlreadyAssigned;
use App\Modules\Identity\Application\Exceptions\PhoneVerificationMethodNotAllowed;
use App\Modules\Identity\Application\Exceptions\TelegramIdentityNotFound;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationMethod;
use App\Modules\Identity\Domain\SmsDeliveryStatus;
use App\Modules\Identity\Domain\VerificationStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class OtpChallengeIssuer
{
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private PhoneLookupHasher $phoneHasher,
        private OtpCodeHasher $codeHasher,
        private OtpAbuseLimiter $limiter,
        private FallbackSmsDispatcher $dispatcher,
        private CustomerIdentityProfileWriter $customerProfiles,
        private RandomGenerator $random,
        private Clock $clock,
        private int $ttlSeconds = 120,
        private int $resendCooldownSeconds = 60,
        private int $maximumAttempts = 5,
        private int $dailyPhoneLimit = 10,
        private int $dailyTelegramAccountLimit = 10,
        private int $dailyIpLimit = 20,
    ) {
        foreach ([
            $ttlSeconds,
            $resendCooldownSeconds,
            $maximumAttempts,
            $dailyPhoneLimit,
            $dailyTelegramAccountLimit,
            $dailyIpLimit,
        ] as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('OTP policy values must be positive.');
            }
        }
    }

    /** @requirement ONB-004 ONB-005 SEC-003 DAT-003 INT-002 */
    public function issue(OtpIssueRequest $request): OtpIssueReceipt
    {
        if (! $request->policy->accepts(PhoneVerificationMethod::SmsOtp)) {
            throw new PhoneVerificationMethodNotAllowed;
        }

        $requestHash = $this->phoneHasher->hashOpaque('otp-request|'.$request->idempotencyKey);
        $existing = $this->existingChallenge($requestHash, $request->number);

        if ($existing !== null) {
            return $this->toReceipt($existing);
        }

        $phoneHash = $this->phoneHasher->hash($request->number);

        try {
            $challenge = $this->database->connection()->transaction(
                fn (): PendingOtpChallenge => $this->prepareChallenge($request, $requestHash, $phoneHash),
            );
        } catch (QueryException $exception) {
            $existing = $this->existingChallenge($requestHash, $request->number);

            if ($existing !== null) {
                return $this->toReceipt($existing);
            }

            $owner = $this->database->connection()->table('phone_numbers')
                ->where('active_lookup_hash', $phoneHash->value)
                ->value('user_id');

            if (is_numeric($owner) && (int) $owner !== $request->userId) {
                throw new PhoneAlreadyAssigned($exception);
            }

            throw $exception;
        }

        if (! $challenge->shouldDispatch()) {
            return $this->toReceipt($challenge);
        }

        $dispatch = $this->dispatcher->dispatch(new SmsOtpMessage(
            $request->number,
            (string) $challenge->plainCode,
            $request->purpose,
            $request->idempotencyKey,
            $request->userId,
            $challenge->challengeId,
        ));
        $finalAttempt = $dispatch->finalAttempt();
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $updates = [
            'provider_code' => $finalAttempt->providerCode,
            'delivery_status' => $finalAttempt->result->status->value,
            'updated_at' => $now,
        ];

        if ($finalAttempt->result->status === SmsDeliveryStatus::DefinitiveFailure) {
            $updates['invalidated_at'] = $now;
            $updates['active_scope_hash'] = null;
        }

        $this->database->connection()->table('otp_challenges')
            ->where('id', $challenge->challengeId)
            ->update($updates);

        return new OtpIssueReceipt(
            $challenge->challengeId,
            $challenge->phoneNumberId,
            $request->number->masked(),
            $challenge->expiresAt,
            $challenge->resendAvailableAt,
            $finalAttempt->result->status,
            $finalAttempt->providerCode,
        );
    }

    private function prepareChallenge(
        OtpIssueRequest $request,
        string $requestHash,
        PhoneLookupHash $phoneHash,
    ): PendingOtpChallenge {
        $connection = $this->database->connection();
        $now = $this->clock->now();
        $nowString = $now->format('Y-m-d H:i:s.u');

        /** @var object{id: int|string}|null $account */
        $account = $connection->table('telegram_accounts')
            ->where('id', $request->telegramAccountId)
            ->where('user_id', $request->userId)
            ->lockForUpdate()
            ->first(['id']);

        if ($account === null) {
            throw new TelegramIdentityNotFound;
        }

        $idempotent = $this->existingChallenge($requestHash, $request->number, true);

        if ($idempotent !== null) {
            return $idempotent;
        }

        $this->assertPhoneIsAvailable($phoneHash, $request->userId);
        $this->releasePreviousPhones($request, $phoneHash, $nowString);
        $phoneNumberId = $this->reservePhone($request, $phoneHash, $nowString);
        $activeScopeHash = $this->phoneHasher->hashOpaque(
            'otp-scope|'.$request->userId.'|'.$phoneHash->value.'|'.$request->purpose,
        );

        /** @var object{id: string, resend_available_at: string}|null $active */
        $active = $connection->table('otp_challenges')
            ->where('active_scope_hash', $activeScopeHash)
            ->lockForUpdate()
            ->first(['id', 'resend_available_at']);

        if ($active !== null) {
            $availableAt = new DateTimeImmutable($active->resend_available_at, new DateTimeZone('UTC'));

            if ($availableAt > $now) {
                throw new OtpResendCooldownActive($availableAt);
            }

            $connection->table('otp_challenges')->where('id', $active->id)->update([
                'invalidated_at' => $nowString,
                'active_scope_hash' => null,
                'updated_at' => $nowString,
            ]);
        }

        $this->limiter->consume([
            new OtpRateLimitBucket('phone', $phoneHash->value, $this->dailyPhoneLimit, 86400),
            new OtpRateLimitBucket(
                'telegram_account',
                (string) $request->telegramAccountId,
                $this->dailyTelegramAccountLimit,
                86400,
            ),
            new OtpRateLimitBucket(
                'ip',
                $this->phoneHasher->hashOpaque('otp-ip|'.$request->requestIp),
                $this->dailyIpLimit,
                86400,
            ),
        ]);

        $challengeId = (string) Str::ulid();
        $code = str_pad((string) $this->random->integer(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = $this->codeHasher->hash($challengeId, $code);
        $expiresAt = $now->modify('+'.$this->ttlSeconds.' seconds');
        $resendAvailableAt = $now->modify('+'.$this->resendCooldownSeconds.' seconds');

        $connection->table('otp_challenges')->insert([
            'id' => $challengeId,
            'user_id' => $request->userId,
            'phone_number_id' => $phoneNumberId,
            'telegram_account_id' => $request->telegramAccountId,
            'purpose' => $request->purpose,
            'channel' => 'sms',
            'destination_lookup_hash' => $phoneHash->value,
            'request_ip_hash' => $this->phoneHasher->hashOpaque('otp-ip|'.$request->requestIp),
            'request_idempotency_hash' => $requestHash,
            'active_scope_hash' => $activeScopeHash,
            'code_hash' => $codeHash->value,
            'code_key_version' => $codeHash->keyVersion,
            'attempt_count' => 0,
            'maximum_attempts' => $this->maximumAttempts,
            'verification_policy' => $request->policy->value,
            'verification_policy_version' => $request->policyVersion,
            'delivery_status' => SmsDeliveryStatus::Pending->value,
            'issued_at' => $nowString,
            'resend_available_at' => $resendAvailableAt->format('Y-m-d H:i:s.u'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
            'created_at' => $nowString,
            'updated_at' => $nowString,
        ]);

        $this->recordEvent(
            $request->userId,
            $phoneNumberId,
            'otp_issued',
            null,
            'pending',
            $request,
            $nowString,
        );

        return new PendingOtpChallenge(
            $challengeId,
            $phoneNumberId,
            $request->number,
            $expiresAt,
            $resendAvailableAt,
            SmsDeliveryStatus::Pending,
            null,
            $code,
        );
    }

    private function assertPhoneIsAvailable(PhoneLookupHash $phoneHash, int $userId): void
    {
        /** @var object{user_id: int|string}|null $conflict */
        $conflict = $this->database->connection()->table('phone_numbers')
            ->where('active_lookup_hash', $phoneHash->value)
            ->lockForUpdate()
            ->first(['user_id']);

        if ($conflict !== null && (int) $conflict->user_id !== $userId) {
            throw new PhoneAlreadyAssigned;
        }
    }

    private function releasePreviousPhones(
        OtpIssueRequest $request,
        PhoneLookupHash $phoneHash,
        string $now,
    ): void {
        /** @var iterable<int, object{id: int|string, status: string}> $phones */
        $phones = $this->database->connection()->table('phone_numbers')
            ->where('active_user_id', $request->userId)
            ->where('lookup_hash', '<>', $phoneHash->value)
            ->lockForUpdate()
            ->get(['id', 'status']);

        foreach ($phones as $phone) {
            $phoneNumberId = (int) $phone->id;
            $this->database->connection()->table('phone_numbers')->where('id', $phoneNumberId)->update([
                'active_user_id' => null,
                'active_lookup_hash' => null,
                'status' => 'released',
                'released_at' => $now,
                'updated_at' => $now,
            ]);
            $this->database->connection()->table('phone_verification_evidences')
                ->where('phone_number_id', $phoneNumberId)
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now, 'updated_at' => $now]);
            $this->database->connection()->table('otp_challenges')
                ->where('phone_number_id', $phoneNumberId)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now, 'active_scope_hash' => null, 'updated_at' => $now]);
            $this->recordEvent(
                $request->userId,
                $phoneNumberId,
                'released',
                $phone->status,
                'released',
                $request,
                $now,
                'number_changed',
            );
        }
    }

    private function reservePhone(
        OtpIssueRequest $request,
        PhoneLookupHash $phoneHash,
        string $now,
    ): int {
        /** @var object{id: int|string}|null $phone */
        $phone = $this->database->connection()->table('phone_numbers')
            ->where('user_id', $request->userId)
            ->where('lookup_hash', $phoneHash->value)
            ->lockForUpdate()
            ->first(['id']);
        $phoneNumberId = $phone === null ? null : (int) $phone->id;
        $contactVerified = $phoneNumberId !== null && $this->hasContactEvidence($phoneNumberId);
        $status = $request->policy->isSatisfied($contactVerified, false) ? 'verified' : 'pending';
        $values = [
            'active_user_id' => $request->userId,
            'encrypted_value' => $this->encrypter->encryptString($request->number->e164()),
            'active_lookup_hash' => $phoneHash->value,
            'hash_key_version' => $phoneHash->keyVersion,
            'status' => $status,
            'verification_policy' => $request->policy->value,
            'verification_policy_version' => $request->policyVersion,
            'released_at' => null,
            'updated_at' => $now,
        ];

        if ($phoneNumberId !== null) {
            $this->database->connection()->table('phone_numbers')->where('id', $phoneNumberId)->update($values);
        } else {
            $phoneNumberId = (int) $this->database->connection()->table('phone_numbers')->insertGetId([
                'user_id' => $request->userId,
                'lookup_hash' => $phoneHash->value,
                'created_at' => $now,
                ...$values,
            ]);
        }

        $connection = $this->database->connection();
        $this->customerProfiles->ensure($connection, $request->userId, $now);
        $this->customerProfiles->updatePhoneVerificationStatus(
            $connection,
            $request->userId,
            VerificationStatus::from($status),
            $now,
        );

        return $phoneNumberId;
    }

    private function hasContactEvidence(int $phoneNumberId): bool
    {
        return $this->database->connection()->table('phone_verification_evidences')
            ->where('phone_number_id', $phoneNumberId)
            ->where('method', PhoneVerificationMethod::TelegramContact->value)
            ->whereNull('invalidated_at')
            ->exists();
    }

    private function existingChallenge(
        string $requestHash,
        IranianMobileNumber $number,
        bool $lock = false,
    ): ?PendingOtpChallenge {
        $query = $this->database->connection()->table('otp_challenges')
            ->where('request_idempotency_hash', $requestHash);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{id: string, phone_number_id: int|string, expires_at: string, resend_available_at: string, delivery_status: string, provider_code: ?string}|null $row */
        $row = $query->first([
            'id',
            'phone_number_id',
            'expires_at',
            'resend_available_at',
            'delivery_status',
            'provider_code',
        ]);

        if ($row === null) {
            return null;
        }

        return new PendingOtpChallenge(
            $row->id,
            (int) $row->phone_number_id,
            $number,
            new DateTimeImmutable($row->expires_at, new DateTimeZone('UTC')),
            new DateTimeImmutable($row->resend_available_at, new DateTimeZone('UTC')),
            SmsDeliveryStatus::from($row->delivery_status),
            $row->provider_code,
            null,
        );
    }

    private function toReceipt(PendingOtpChallenge $challenge): OtpIssueReceipt
    {
        return new OtpIssueReceipt(
            $challenge->challengeId,
            $challenge->phoneNumberId,
            $challenge->number->masked(),
            $challenge->expiresAt,
            $challenge->resendAvailableAt,
            $challenge->deliveryStatus,
            $challenge->providerCode,
        );
    }

    private function recordEvent(
        int $userId,
        int $phoneNumberId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        OtpIssueRequest $request,
        string $createdAt,
        ?string $reasonCode = null,
    ): void {
        $this->database->connection()->table('phone_verification_events')->insert([
            'user_id' => $userId,
            'phone_number_id' => $phoneNumberId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'method' => PhoneVerificationMethod::SmsOtp->value,
            'policy_version' => $request->policyVersion,
            'telegram_account_id' => $request->telegramAccountId,
            'reason_code' => $reasonCode,
            'correlation_id' => $request->correlationId,
            'created_at' => $createdAt,
        ]);
    }
}
