<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Application\Exceptions\PhoneAlreadyAssigned;
use App\Modules\Identity\Application\Exceptions\PhoneVerificationMethodNotAllowed;
use App\Modules\Identity\Application\Exceptions\TelegramContactOwnershipMismatch;
use App\Modules\Identity\Application\Exceptions\TelegramIdentityNotFound;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationMethod;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
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
        private Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $contact
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

        $phoneValue = $contact['phone_number'] ?? null;

        if (! is_string($phoneValue)) {
            throw new InvalidArgumentException('Telegram contact phone number is missing.');
        }

        $number = IranianMobileNumber::fromString($phoneValue);
        $lookup = $this->hasher->hash($number);

        try {
            return $this->database->connection()->transaction(function () use (
                $userId,
                $botId,
                $senderTelegramUserId,
                $policy,
                $policyVersion,
                $correlationId,
                $number,
                $lookup,
            ): PhoneVerificationReceipt {
                $connection = $this->database->connection();
                $now = $this->clock->now()->format('Y-m-d H:i:s.u');

                /** @var object{id: int|string}|null $telegramAccount */
                $telegramAccount = $connection->table('telegram_accounts')
                    ->where('user_id', $userId)
                    ->where('bot_id', $botId)
                    ->where('telegram_user_id', $senderTelegramUserId)
                    ->lockForUpdate()
                    ->first(['id']);

                if ($telegramAccount === null) {
                    throw new TelegramIdentityNotFound;
                }

                $telegramAccountId = (int) $telegramAccount->id;

                /** @var object{user_id: int|string}|null $conflict */
                $conflict = $connection->table('phone_numbers')
                    ->where('active_lookup_hash', $lookup->value)
                    ->lockForUpdate()
                    ->first(['user_id']);

                if ($conflict !== null && (int) $conflict->user_id !== $userId) {
                    throw new PhoneAlreadyAssigned;
                }

                /** @var iterable<int, object{id: int|string, status: string}> $releasedPhones */
                $releasedPhones = $connection->table('phone_numbers')
                    ->where('active_user_id', $userId)
                    ->where('lookup_hash', '<>', $lookup->value)
                    ->lockForUpdate()
                    ->get(['id', 'status']);

                foreach ($releasedPhones as $releasedPhone) {
                    $releasedPhoneId = (int) $releasedPhone->id;
                    $connection->table('phone_numbers')->where('id', $releasedPhoneId)->update([
                        'active_user_id' => null,
                        'active_lookup_hash' => null,
                        'status' => 'released',
                        'released_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $connection->table('phone_verification_evidences')
                        ->where('phone_number_id', $releasedPhoneId)
                        ->whereNull('invalidated_at')
                        ->update(['invalidated_at' => $now, 'updated_at' => $now]);
                    $this->recordEvent(
                        $userId,
                        $releasedPhoneId,
                        'released',
                        $releasedPhone->status,
                        'released',
                        null,
                        $policyVersion,
                        $telegramAccountId,
                        'number_changed',
                        $correlationId,
                        $now,
                    );
                }

                /** @var object{id: int|string, status: string}|null $existingPhone */
                $existingPhone = $connection->table('phone_numbers')
                    ->where('user_id', $userId)
                    ->where('lookup_hash', $lookup->value)
                    ->lockForUpdate()
                    ->first(['id', 'status']);

                if ($existingPhone === null) {
                    $phoneNumberId = (int) $connection->table('phone_numbers')->insertGetId([
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
                    $previousStatus = null;
                } else {
                    $phoneNumberId = (int) $existingPhone->id;
                    $previousStatus = $existingPhone->status;
                }

                $connection->table('phone_verification_evidences')->insertOrIgnore([
                    'phone_number_id' => $phoneNumberId,
                    'method' => PhoneVerificationMethod::TelegramContact->value,
                    'policy_version' => $policyVersion,
                    'verified_at' => $now,
                    'telegram_account_id' => $telegramAccountId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $connection->table('phone_verification_evidences')
                    ->where('phone_number_id', $phoneNumberId)
                    ->where('method', PhoneVerificationMethod::TelegramContact->value)
                    ->update([
                        'policy_version' => $policyVersion,
                        'verified_at' => $now,
                        'invalidated_at' => null,
                        'telegram_account_id' => $telegramAccountId,
                        'updated_at' => $now,
                    ]);

                $methodValues = $connection->table('phone_verification_evidences')
                    ->where('phone_number_id', $phoneNumberId)
                    ->whereNull('invalidated_at')
                    ->pluck('method')
                    ->all();
                $contactVerified = in_array(PhoneVerificationMethod::TelegramContact->value, $methodValues, true);
                $otpVerified = in_array(PhoneVerificationMethod::SmsOtp->value, $methodValues, true);
                $policySatisfied = $policy->isSatisfied($contactVerified, $otpVerified);
                $status = $policySatisfied ? 'verified' : 'pending';

                $connection->table('phone_numbers')->where('id', $phoneNumberId)->update([
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

                $tierId = $connection->table('customer_tiers')->where('code', 'new')->value('id');
                $connection->table('customer_profiles')->insertOrIgnore([
                    'user_id' => $userId,
                    'current_tier_id' => is_numeric($tierId) ? (int) $tierId : null,
                    'tier_locked' => false,
                    'phone_verification_status' => 'unverified',
                    'identity_verification_status' => 'unverified',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $connection->table('customer_profiles')->where('user_id', $userId)->update([
                    'phone_verification_status' => $status,
                    'updated_at' => $now,
                ]);

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
            });
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
