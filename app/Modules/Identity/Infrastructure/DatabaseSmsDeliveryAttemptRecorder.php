<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Modules\Identity\Application\SmsDeliveryAttempt;
use App\Modules\Identity\Application\SmsOtpMessage;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DatabaseSmsDeliveryAttemptRecorder implements SmsDeliveryAttemptRecorder
{
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private PhoneLookupHasher $hasher,
        private Clock $clock,
    ) {}

    public function record(SmsOtpMessage $message, SmsDeliveryAttempt $attempt, int $sequence): void
    {
        if ($sequence < 1 || $sequence > 10) {
            throw new InvalidArgumentException('SMS attempt sequence is outside the supported range.');
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $phoneHash = $this->hasher->hash($message->destination);

        $this->database->connection()->table('sms_delivery_attempts')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'user_id' => $message->userId,
            'otp_challenge_id' => $message->challengeId,
            'provider_code' => $attempt->providerCode,
            'destination_lookup_hash' => $phoneHash->value,
            'idempotency_key_hash' => $this->hasher->hashOpaque($message->idempotencyKey),
            'attempt_number' => $sequence,
            'outcome' => $attempt->result->status->value,
            'provider_message_id_ciphertext' => $attempt->result->providerMessageId === null
                ? null
                : $this->encrypter->encryptString($attempt->result->providerMessageId),
            'error_code' => $attempt->result->errorCode,
            'created_at' => $now,
        ]);
    }
}
