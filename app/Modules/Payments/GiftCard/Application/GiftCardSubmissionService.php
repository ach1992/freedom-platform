<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class GiftCardSubmissionService
{
    private const METHOD_CODE = 'gift_card';

    public function __construct(
        private DatabaseManager $database,
        private PurchasePaymentIntentService $purchaseIntents,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    /** @requirement GFT-001 GFT-002 GFT-003 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function submit(
        string $submissionKey,
        string $intentCreationKey,
        int $userId,
        string $quotePublicId,
        string $eligibilityDecisionPublicId,
        string $typeCode,
        int $claimedFaceValue,
        string $claimedCurrency,
        string $claimedBrand,
        ?string $claimedRegion,
        ?string $code,
        ?string $privateImageReference,
        ?string $telegramFileId,
        ?string $telegramFileUniqueId,
        ?string $imageContentHash,
        string $correlationId,
    ): GiftCardSubmissionReceipt {
        $this->assertToken($submissionKey, 'Gift-card submission key', 8, 128);
        $this->assertToken($intentCreationKey, 'Gift-card intent creation key', 8, 128);
        if ($userId < 1 || ! Str::isUlid($quotePublicId) || ! Str::isUlid($eligibilityDecisionPublicId)) {
            throw new DomainException('Gift-card purchase identity is invalid.');
        }
        $this->assertToken($typeCode, 'Gift-card type code', 2, 64);
        if ($claimedFaceValue < 1) {
            throw new DomainException('Gift-card claimed face value must be positive.');
        }
        $claimedCurrency = strtoupper(trim($claimedCurrency));
        if (preg_match('/\A[A-Z]{3}\z/', $claimedCurrency) !== 1) {
            throw new DomainException('Gift-card claimed currency is invalid.');
        }
        $claimedBrand = $this->bounded($claimedBrand, 64, 'Gift-card claimed brand');
        $claimedRegion = $this->boundedOptional($claimedRegion, 64, 'Gift-card claimed region');
        $normalizedCode = $this->normalizeCode($code);
        $privateImageReference = $this->boundedOptional($privateImageReference, 191, 'Gift-card private image reference');
        $telegramFileId = $this->boundedOptional($telegramFileId, 191, 'Gift-card Telegram file ID');
        $telegramFileUniqueId = $this->boundedOptional($telegramFileUniqueId, 191, 'Gift-card Telegram unique file ID');
        $imageContentHash = $this->normalizeHash($imageContentHash, 'Gift-card image content hash');
        $this->assertToken($correlationId, 'Gift-card submission correlation ID', 8, 64);

        if ($privateImageReference !== null && $imageContentHash === null) {
            throw new DomainException('Gift-card image evidence requires a content hash.');
        }
        if ($privateImageReference === null && ($telegramFileId !== null || $telegramFileUniqueId !== null || $imageContentHash !== null)) {
            throw new DomainException('Gift-card image metadata requires a private image reference.');
        }

        $keyVersion = $normalizedCode === null ? null : $this->lookupKeyVersion();
        $codeHash = $normalizedCode === null ? null : hash_hmac('sha256', $normalizedCode, $this->lookupKey());
        $previousLookup = $normalizedCode === null || $keyVersion === null ? null : $this->previousLookupKey($keyVersion);
        $previousCodeHash = $previousLookup === null || $normalizedCode === null ? null : hash_hmac('sha256', $normalizedCode, $previousLookup['key']);
        $previousKeyVersion = $previousLookup['version'] ?? null;
        $maskedCode = $normalizedCode === null ? null : $this->maskCode($normalizedCode);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $submissionKey,
                $intentCreationKey,
                $userId,
                $quotePublicId,
                $eligibilityDecisionPublicId,
                $typeCode,
                $claimedFaceValue,
                $claimedCurrency,
                $claimedBrand,
                $claimedRegion,
                $normalizedCode,
                $privateImageReference,
                $telegramFileId,
                $telegramFileUniqueId,
                $imageContentHash,
                $keyVersion,
                $codeHash,
                $previousCodeHash,
                $previousKeyVersion,
                $maskedCode,
                $correlationId,
            ): GiftCardSubmissionReceipt {
                $type = $connection->table('gift_card_types')->where('type_code', $typeCode)->lockForUpdate()->first();
                if ($type === null) {
                    throw new DomainException('Gift-card type does not exist.');
                }
                $this->assertClaimMatchesType($type, $claimedCurrency, $claimedBrand, $claimedRegion);
                $this->assertSubmissionMode($type->submission_mode, $normalizedCode, $privateImageReference);

                $intentReceipt = $this->purchaseIntents->create(
                    $intentCreationKey,
                    $userId,
                    $quotePublicId,
                    $eligibilityDecisionPublicId,
                    self::METHOD_CODE,
                    $correlationId,
                );
                $intent = $connection->table('payment_intents')
                    ->where('public_id', $intentReceipt->intentPublicId)
                    ->lockForUpdate()
                    ->first(['id', 'public_id', 'user_id', 'purpose', 'payment_method_code', 'provider_code', 'amount_irr', 'currency', 'state', 'captured_at']);
                if ($intent === null) {
                    throw new RuntimeException('Gift-card purchase intent authority is unavailable.');
                }

                $payloadHash = $this->requestPayloadHash(
                    (int) $intent->id,
                    (int) $type->id,
                    (int) $type->version,
                    (string) $type->configuration_hash,
                    $userId,
                    $codeHash,
                    $keyVersion,
                    $privateImageReference,
                    $telegramFileId,
                    $telegramFileUniqueId,
                    $imageContentHash,
                    $claimedFaceValue,
                    $claimedCurrency,
                    $claimedBrand,
                    $claimedRegion,
                );

                $previousPayloadHash = null;
                if ($previousCodeHash !== null && $previousKeyVersion !== null) {
                    $previousPayloadHash = $this->requestPayloadHash(
                        (int) $intent->id,
                        (int) $type->id,
                        (int) $type->version,
                        (string) $type->configuration_hash,
                        $userId,
                        $previousCodeHash,
                        $previousKeyVersion,
                        $privateImageReference,
                        $telegramFileId,
                        $telegramFileUniqueId,
                        $imageContentHash,
                        $claimedFaceValue,
                        $claimedCurrency,
                        $claimedBrand,
                        $claimedRegion,
                    );
                }

                $existing = $connection->table('gift_card_submissions')->where('submission_key', $submissionKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ((int) $existing->payment_intent_id !== (int) $intent->id
                        || (int) $existing->gift_card_type_id !== (int) $type->id
                        || (! hash_equals(strtolower((string) $existing->request_payload_hash), $payloadHash)
                            && ($previousPayloadHash === null
                                || ! hash_equals(strtolower((string) $existing->request_payload_hash), $previousPayloadHash)))) {
                        throw new RuntimeException('Gift-card submission key conflicts with accepted evidence.');
                    }

                    return $this->receipt($connection, $existing, true);
                }

                if ($previousCodeHash !== null) {
                    $historicalDuplicate = $connection->table('gift_card_submissions')
                        ->where('code_lookup_hash', $previousCodeHash)
                        ->lockForUpdate()
                        ->first(['id', 'public_id']);
                    if ($historicalDuplicate !== null) {
                        throw new RuntimeException('Gift-card evidence is already bound to another purchase.');
                    }
                }

                if (! (bool) $type->active) {
                    throw new DomainException('Gift-card type is not active.');
                }
                if ((int) $intent->user_id !== $userId
                    || $intent->purpose !== 'purchase'
                    || $intent->payment_method_code !== self::METHOD_CODE
                    || $intent->provider_code !== self::METHOD_CODE
                    || $intent->state !== PaymentIntentState::AwaitingUserAction->value
                    || $intent->captured_at !== null) {
                    throw new DomainException('Gift-card submission does not match an awaiting purchase intent.');
                }

                $now = $this->timestamp();
                $submissionId = (int) $connection->table('gift_card_submissions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'submission_key' => $submissionKey,
                    'payment_intent_id' => (int) $intent->id,
                    'gift_card_type_id' => (int) $type->id,
                    'gift_card_type_version_snapshot' => (int) $type->version,
                    'gift_card_type_configuration_hash' => strtolower((string) $type->configuration_hash),
                    'user_id' => $userId,
                    'encrypted_code' => $normalizedCode === null ? null : $this->encrypter->encryptString($normalizedCode),
                    'code_lookup_hash' => $codeHash,
                    'code_lookup_key_version' => $keyVersion,
                    'masked_code' => $maskedCode,
                    'private_image_reference' => $privateImageReference,
                    'telegram_file_id' => $telegramFileId,
                    'telegram_file_unique_id' => $telegramFileUniqueId,
                    'image_content_hash' => $imageContentHash,
                    'claimed_face_value' => $claimedFaceValue,
                    'claimed_currency' => $claimedCurrency,
                    'claimed_brand' => $claimedBrand,
                    'claimed_region' => $claimedRegion,
                    'state' => 'submitted',
                    'request_payload_hash' => $payloadHash,
                    'submitted_at' => $now,
                    'created_at' => $now,
                ]);

                $updated = $connection->table('payment_intents')
                    ->where('id', $intent->id)
                    ->where('state', PaymentIntentState::AwaitingUserAction->value)
                    ->whereNull('captured_at')
                    ->update([
                        'state' => PaymentIntentState::Submitted->value,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Gift-card payment intent state changed concurrently.');
                }
                $connection->table('payment_intent_state_histories')->insert([
                    'payment_intent_id' => (int) $intent->id,
                    'from_state' => PaymentIntentState::AwaitingUserAction->value,
                    'to_state' => PaymentIntentState::Submitted->value,
                    'reason_code' => 'gift_card_submitted',
                    'correlation_id' => $correlationId,
                    'created_at' => $now,
                ]);

                $stored = $connection->table('gift_card_submissions')->where('id', $submissionId)->first();
                if ($stored === null) {
                    throw new RuntimeException('Gift-card submission persistence failed.');
                }

                return $this->receipt($connection, $stored, false);
            }, 3);
        } catch (QueryException $exception) {
            $connection = $this->database->connection();
            $duplicate = null;
            if ($codeHash !== null) {
                $duplicate = $connection->table('gift_card_submissions')->where('code_lookup_hash', $codeHash)->first(['id', 'public_id']);
            }
            if ($duplicate === null && $imageContentHash !== null) {
                $duplicate = $connection->table('gift_card_submissions')->where('image_content_hash', $imageContentHash)->first(['id', 'public_id']);
            }
            if ($duplicate === null && $telegramFileUniqueId !== null) {
                $duplicate = $connection->table('gift_card_submissions')->where('telegram_file_unique_id', $telegramFileUniqueId)->first(['id', 'public_id']);
            }
            if ($duplicate !== null) {
                $duplicateEvidenceHash = $codeHash
                    ?? $imageContentHash
                    ?? ($telegramFileUniqueId === null ? null : hash('sha256', $telegramFileUniqueId));
                $this->recordDuplicateFindingSafely(
                    (int) $duplicate->id,
                    (string) $duplicate->public_id,
                    $duplicateEvidenceHash,
                    $correlationId,
                );
                throw new RuntimeException('Gift-card evidence is already bound to another purchase.', 0, $exception);
            }

            throw $exception;
        } catch (RuntimeException $exception) {
            if ($previousCodeHash !== null
                && $exception->getMessage() === 'Gift-card evidence is already bound to another purchase.') {
                $connection = $this->database->connection();
                $duplicate = $connection->table('gift_card_submissions')
                    ->where('code_lookup_hash', $previousCodeHash)
                    ->first(['id', 'public_id']);
                if ($duplicate !== null) {
                    $this->recordDuplicateFindingSafely(
                        (int) $duplicate->id,
                        (string) $duplicate->public_id,
                        $previousCodeHash,
                        $correlationId,
                    );
                }
            }

            throw $exception;
        }
    }

    private function recordDuplicateFindingSafely(
        int $submissionId,
        string $submissionPublicId,
        ?string $evidenceHash,
        string $correlationId,
    ): void {
        try {
            $findingKey = hash('sha256', implode("\0", [
                'duplicate_submission_evidence',
                $submissionPublicId,
                $evidenceHash ?? '',
            ]));
            $this->database->connection()->table('gift_card_reconciliation_findings')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'gift_card_submission_id' => $submissionId,
                'finding_key' => $findingKey,
                'finding_type' => 'duplicate_submission_evidence',
                'severity' => 'high',
                'provider_code' => null,
                'provider_event_id' => null,
                'provider_transaction_id' => null,
                'evidence_hash' => $evidenceHash,
                'correlation_id' => $correlationId,
                'created_at' => $this->timestamp(),
            ]);
        } catch (Throwable) {
            // Preserve the original duplicate-evidence rejection.
        }
    }

    private function assertClaimMatchesType(stdClass $type, string $currency, string $brand, ?string $region): void
    {
        if ((string) $type->face_currency !== $currency
            || (string) $type->brand !== $brand
            || (($type->region === null) !== ($region === null))
            || ($region !== null && ! hash_equals((string) $type->region, $region))) {
            throw new DomainException('Gift-card claim does not match the selected type policy.');
        }
    }

    private function assertSubmissionMode(string $mode, ?string $code, ?string $imageReference): void
    {
        $valid = match ($mode) {
            'image_only' => $code === null && $imageReference !== null,
            'code_only' => $code !== null && $imageReference === null,
            'either' => $code !== null || $imageReference !== null,
            'both' => $code !== null && $imageReference !== null,
            default => false,
        };
        if (! $valid) {
            throw new DomainException('Gift-card submission evidence does not satisfy the selected type mode.');
        }
    }

    private function requestPayloadHash(
        int $intentId,
        int $typeId,
        int $typeVersion,
        string $typeConfigurationHash,
        int $userId,
        ?string $codeHash,
        ?int $keyVersion,
        ?string $imageReference,
        ?string $telegramFileId,
        ?string $telegramFileUniqueId,
        ?string $imageContentHash,
        int $faceValue,
        string $currency,
        string $brand,
        ?string $region,
    ): string {
        return hash('sha256', json_encode([
            'payment_intent_id' => $intentId,
            'gift_card_type_id' => $typeId,
            'gift_card_type_version' => $typeVersion,
            'gift_card_type_configuration_hash' => strtolower($typeConfigurationHash),
            'user_id' => $userId,
            'code_lookup_hash' => $codeHash,
            'code_lookup_key_version' => $keyVersion,
            'private_image_reference_hash' => $imageReference === null ? null : hash('sha256', $imageReference),
            'telegram_file_id_hash' => $telegramFileId === null ? null : hash('sha256', $telegramFileId),
            'telegram_file_unique_id_hash' => $telegramFileUniqueId === null ? null : hash('sha256', $telegramFileUniqueId),
            'image_content_hash' => $imageContentHash,
            'claimed_face_value' => $faceValue,
            'claimed_currency' => $currency,
            'claimed_brand' => $brand,
            'claimed_region' => $region,
        ], JSON_THROW_ON_ERROR));
    }

    private function receipt(Connection $connection, stdClass $row, bool $replayed): GiftCardSubmissionReceipt
    {
        $intent = $connection->table('payment_intents')->where('id', $row->payment_intent_id)->first(['public_id']);
        $type = $connection->table('gift_card_types')->where('id', $row->gift_card_type_id)->first(['type_code']);
        if ($intent === null || $type === null) {
            throw new RuntimeException('Gift-card submission linked authority is unavailable.');
        }

        return new GiftCardSubmissionReceipt(
            (int) $row->id,
            (string) $row->public_id,
            (string) $intent->public_id,
            (string) $type->type_code,
            $row->masked_code === null ? '' : (string) $row->masked_code,
            (int) $row->claimed_face_value,
            (string) $row->claimed_currency,
            (string) $row->state,
            $replayed,
        );
    }

    private function normalizeCode(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $length = strlen($value);
        if ($length < 4 || $length > 128 || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw new DomainException('Gift-card code format is invalid.');
        }

        return $value;
    }

    private function maskCode(string $value): string
    {
        $length = strlen($value);
        if ($length <= 4) {
            return substr($value, 0, 1).str_repeat('*', max(1, $length - 2)).substr($value, -1);
        }
        $prefix = substr($value, 0, min(4, $length - 2));
        $suffix = substr($value, -2);

        return $prefix.str_repeat('*', max(4, min(16, $length - strlen($prefix) - 2))).$suffix;
    }

    private function normalizeHash(?string $value, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = strtolower(trim($value));
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }

        return $value;
    }

    private function lookupKey(): string
    {
        $key = config('payments.gift_card.code_lookup_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Gift-card code lookup key is not configured securely.');
        }

        return $key;
    }

    private function lookupKeyVersion(): int
    {
        $version = filter_var(config('payments.gift_card.code_lookup_key_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version === false) {
            throw new RuntimeException('Gift-card code lookup key version is invalid.');
        }

        return (int) $version;
    }

    /** @return array{key:string,version:int}|null */
    private function previousLookupKey(int $currentVersion): ?array
    {
        $key = config('payments.gift_card.code_lookup_previous_key');
        $rawVersion = config('payments.gift_card.code_lookup_previous_key_version');
        $keyConfigured = is_string($key) && trim($key) !== '';
        $versionConfigured = $rawVersion !== null && $rawVersion !== '';

        if (! $keyConfigured && ! $versionConfigured) {
            return null;
        }
        if (! $keyConfigured || strlen($key) < 32) {
            throw new RuntimeException('Gift-card previous code lookup key is not configured securely.');
        }

        $version = filter_var($rawVersion, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version === false || (int) $version >= $currentVersion) {
            throw new RuntimeException('Gift-card previous code lookup key version is invalid.');
        }

        return ['key' => $key, 'version' => (int) $version];
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function bounded(string $value, int $maximum, string $label): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label.' is invalid.');
        }

        return $value;
    }

    private function boundedOptional(?string $value, int $maximum, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->bounded($value, $maximum, $label);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
