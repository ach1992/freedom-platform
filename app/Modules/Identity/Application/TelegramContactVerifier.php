<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Contracts\CustomerIdentityProfileWriter;
use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Application\Exceptions\PhoneAlreadyAssigned;
use App\Modules\Identity\Application\Exceptions\PhoneVerificationMethodNotAllowed;
use App\Modules\Identity\Application\Exceptions\TelegramContactOwnershipMismatch;
use App\Modules\Identity\Application\Exceptions\TelegramIdentityNotFound;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationMethod;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use App\Modules\Identity\Domain\VerificationStatus;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final readonly class TelegramContactVerifier
{
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private PhoneLookupHasher $hasher,
        private CustomerIdentityProfileWriter $customerProfiles,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $contact
     *
     * @requirement ONB-004 ONB-005 USR-001 SEC-003 DAT-003
     */
    public function verify(
        int $userId,
        string $botId,
        int $senderTelegramUserId,
        array $contact,
        PhoneVerificationPolicy $policy,
        int $policyVersion,
        ?string $correlationId = null,
    ): PhoneVerificationReceipt {
        $this->assertRequestIsValid(
            $userId,
            $botId,
            $senderTelegramUserId,
            $contact,
            $policy,
            $policyVersion,
            $correlationId,
        );

        /** @var string $phoneValue */
        $phoneValue = $contact['phone_number'];
        $number = IranianMobileNumber::fromString($phoneValue);
        $lookup = $this->hasher->hash($number);

        try {
            return $this->database->connection()->transaction(
                fn (): PhoneVerificationReceipt => $this->verifyWithinTransaction(
                    $userId,
                    $botId,
                    $senderTelegramUserId,
                    $number,
                    $lookup,
                    $policy,
                    $policyVersion,
                    $correlationId,
                ),
            );
        } catch (QueryException $exception) {
            $owner = $this->database->connection()->table('phone_numbers')
                ->where('active_lookup_hash', $lookup->value)
                ->value('user_id');

            if (is_numeric($owner) && (int) $owner !== $userId) {
                throw new PhoneAlreadyAssigned($exception);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $contact */
    private function assertRequestIsValid(
        int $userId,
        string $botId,
        int $senderTelegramUserId,
        array $contact,
        PhoneVerificationPolicy $policy,
        int $policyVersion,
        ?string $correlationId,
    ): void {
        if ($userId < 1 || $senderTelegramUserId < 1 || preg_match('/\A[1-9][0-9]{0,19}\z/', $botId) !== 1) {
            throw new InvalidArgumentException('Telegram contact verification identity is invalid.');
        }

        if ($policyVersion < 1) {
            throw new InvalidArgumentException('Phone verification policy version must be positive.');
        }

        if ($correlationId !== null && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Phone verification correlation ID is invalid.');
        }

        if (! $policy->accepts(PhoneVerificationMethod::TelegramContact)) {
            throw new PhoneVerificationMethodNotAllowed;
        }

        $contactUserId = $contact['user_id'] ?? null;

        if (! is_int($contactUserId) || $contactUserId !== $senderTelegramUserId) {
            throw new TelegramContactOwnershipMismatch;
        }

        if (! is_string($contact['phone_number'] ?? null)) {
            throw new InvalidArgumentException('Telegram contact phone number is missing.');
        }
    }

    private function verifyWithinTransaction(
        int $userId,
        string $botId,
        int $senderTelegramUserId,
        IranianMobileNumber $number,
        PhoneLookupHash $lookup,
        PhoneVerificationPolicy $policy,
        int $policyVersion,
        ?string $correlationId,
    ): PhoneVerificationReceipt {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $telegramAccountId = $this->telegramAccountId($userId, $botId, $senderTelegramUserId);
        $this->assertPhoneIsAvailable($lookup, $userId);
        $this->releasePreviousPhones(
            $userId,
            $lookup,
            $policyVersion,
            $telegramAccountId,
            $correlationId,
            $now,
        );

        [$phoneNumberId, $previousStatus] = $this->findOrCreatePhone(
            $userId,
            $number,
            $lookup,
            $policy,
            $policyVersion,
            $telegramAccountId,
            $now,
        );
        $this->recordContactEvidence($phoneNumberId, $policyVersion, $telegramAccountId, $now);

        $policySatisfied = $this->isPolicySatisfied($phoneNumberId, $policy);
        $status = $policySatisfied ? 'verified' : 'pending';
        $this->activatePhone(
            $phoneNumberId,
            $userId,
            $number,
            $lookup,
            $policy,
            $policyVersion,
            $telegramAccountId,
            $policySatisfied,
            $status,
            $now,
        );
        $connection = $this->database->connection();
        $this->customerProfiles->ensure($connection, $userId, $now);
        $this->customerProfiles->updatePhoneVerificationStatus(
            $connection,
            $userId,
            VerificationStatus::from($status),
            $now,
        );
        $this->recordEvent(
            $userId,
            $phoneNumberId,
            $previousStatus === null ? 'contact_verified' : 'contact_reverified',
            $previousStatus,
            $status,
            PhoneVerificationMethod::TelegramContact,
            $policyVersion,
            $telegramAccountId,
            null,
            $correlationId,
            $now,
        );

        return new PhoneVerificationReceipt(
            $phoneNumberId,
            $number,
            PhoneVerificationMethod::TelegramContact,
            $policy,
            $policyVersion,
            $policySatisfied,
        );
    }

    private function telegramAccountId(int $userId, string $botId, int $senderTelegramUserId): int
    {
        /** @var object{id: int|string}|null $account */
        $account = $this->database->connection()->table('telegram_accounts')
            ->where('user_id', $userId)
            ->where('bot_id', $botId)
            ->where('telegram_user_id', $senderTelegramUserId)
            ->lockForUpdate()
            ->first(['id']);

        if ($account === null) {
            throw new TelegramIdentityNotFound;
        }

        return (int) $account->id;
    }

    private function assertPhoneIsAvailable(PhoneLookupHash $lookup, int $userId): void
    {
        /** @var object{user_id: int|string}|null $conflict */
        $conflict = $this->database->connection()->table('phone_numbers')
            ->where('active_lookup_hash', $lookup->value)
            ->lockForUpdate()
            ->first(['user_id']);

        if ($conflict !== null && (int) $conflict->user_id !== $userId) {
            throw new PhoneAlreadyAssigned;
        }
    }

    private function releasePreviousPhones(
        int $userId,
        PhoneLookupHash $lookup,
        int $policyVersion,
        int $telegramAccountId,
        ?string $correlationId,
        string $now,
    ): void {
        /** @var iterable<int, object{id: int|string, status: string}> $phones */
        $phones = $this->database->connection()->table('phone_numbers')
            ->where('active_user_id', $userId)
            ->where('lookup_hash', '<>', $lookup->value)
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
                ->update([
                    'invalidated_at' => $now,
                    'active_scope_hash' => null,
                    'updated_at' => $now,
                ]);
            $this->recordEvent(
                $userId,
                $phoneNumberId,
                'released',
                $phone->status,
                'released',
                null,
                $policyVersion,
                $telegramAccountId,
                'number_changed',
                $correlationId,
                $now,
            );
        }
    }

    /** @return array{int, string|null} */
    private function findOrCreatePhone(
        int $userId,
        IranianMobileNumber $number,
        PhoneLookupHash $lookup,
        PhoneVerificationPolicy $policy,
        int $policyVersion,
        int $telegramAccountId,
        string $now,
    ): array {
        /** @var object{id: int|string, status: string}|null $phone */
        $phone = $this->database->connection()->table('phone_numbers')
            ->where('user_id', $userId)
            ->where('lookup_hash', $lookup->value)
            ->lockForUpdate()
            ->first(['id', 'status']);

        if ($phone !== null) {
            return [(int) $phone->id, $phone->status];
        }

        $phoneNumberId = (int) $this->database->connection()->table('phone_numbers')->insertGetId([
            'user_id' => $userId,
            'active_user_id' => $userId,
            'encrypted_value' => $this->encrypter->encryptString($number->e164()),
            'lookup_hash' => $lookup->value,
            'active_lookup_hash' => $lookup->value,
            'hash_key_version' => $lookup->keyVersion,
            'status' => 'pending',
            'verification_policy' => $policy->value,
            'verification_policy_version' => $policyVersion,
            'last_verification_method' => PhoneVerificationMethod::TelegramContact->value,
            'verified_via_telegram_account_id' => $telegramAccountId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$phoneNumberId, null];
    }

    private function recordContactEvidence(
        int $phoneNumberId,
        int $policyVersion,
        int $telegramAccountId,
        string $now,
    ): void {
        $this->database->connection()->table('phone_verification_evidences')->insertOrIgnore([
            'phone_number_id' => $phoneNumberId,
            'method' => PhoneVerificationMethod::TelegramContact->value,
            'policy_version' => $policyVersion,
            'verified_at' => $now,
            'telegram_account_id' => $telegramAccountId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->database->connection()->table('phone_verification_evidences')
            ->where('phone_number_id', $phoneNumberId)
            ->where('method', PhoneVerificationMethod::TelegramContact->value)
            ->update([
                'policy_version' => $policyVersion,
                'verified_at' => $now,
                'invalidated_at' => null,
                'telegram_account_id' => $telegramAccountId,
                'updated_at' => $now,
            ]);
    }

    private function isPolicySatisfied(int $phoneNumberId, PhoneVerificationPolicy $policy): bool
    {
        $methods = $this->database->connection()->table('phone_verification_evidences')
            ->where('phone_number_id', $phoneNumberId)
            ->whereNull('invalidated_at')
            ->pluck('method')
            ->all();

        return $policy->isSatisfied(
            in_array(PhoneVerificationMethod::TelegramContact->value, $methods, true),
            in_array(PhoneVerificationMethod::SmsOtp->value, $methods, true),
        );
    }

    private function activatePhone(
        int $phoneNumberId,
        int $userId,
        IranianMobileNumber $number,
        PhoneLookupHash $lookup,
        PhoneVerificationPolicy $policy,
        int $policyVersion,
        int $telegramAccountId,
        bool $policySatisfied,
        string $status,
        string $now,
    ): void {
        $this->database->connection()->table('phone_numbers')->where('id', $phoneNumberId)->update([
            'active_user_id' => $userId,
            'encrypted_value' => $this->encrypter->encryptString($number->e164()),
            'active_lookup_hash' => $lookup->value,
            'hash_key_version' => $lookup->keyVersion,
            'status' => $status,
            'verification_policy' => $policy->value,
            'verification_policy_version' => $policyVersion,
            'last_verification_method' => PhoneVerificationMethod::TelegramContact->value,
            'verified_via_telegram_account_id' => $telegramAccountId,
            'verified_at' => $policySatisfied ? $now : null,
            'released_at' => null,
            'updated_at' => $now,
        ]);
    }

    private function recordEvent(
        int $userId,
        int $phoneNumberId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        ?PhoneVerificationMethod $method,
        int $policyVersion,
        int $telegramAccountId,
        ?string $reasonCode,
        ?string $correlationId,
        string $createdAt,
    ): void {
        $this->database->connection()->table('phone_verification_events')->insert([
            'user_id' => $userId,
            'phone_number_id' => $phoneNumberId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'method' => $method?->value,
            'policy_version' => $policyVersion,
            'telegram_account_id' => $telegramAccountId,
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'created_at' => $createdAt,
        ]);
    }
}
