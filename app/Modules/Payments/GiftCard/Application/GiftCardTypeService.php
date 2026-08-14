<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class GiftCardTypeService
{
    private const SUBMISSION_MODES = ['image_only', 'code_only', 'either', 'both'];
    private const VERIFICATION_MODES = [
        'manual_only',
        'automatic_only',
        'automatic_then_manual',
        'automatic_with_manual_approval_above_limit',
        'manual_fallback_on_provider_failure',
    ];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement GFT-001 GFT-003 DAT-002 DAT-003 SEC-002 QUA-004 */
    public function register(
        string $typeCode,
        string $displayName,
        string $brand,
        ?string $region,
        string $faceCurrency,
        string $submissionMode,
        string $verificationMode,
        ?int $manualApprovalLimitFaceValue,
        string $providerCode,
    ): GiftCardTypeReceipt {
        $this->assertToken($typeCode, 'Gift-card type code', 2, 64);
        $displayName = $this->bounded($displayName, 128, 'Gift-card type display name');
        $brand = $this->bounded($brand, 64, 'Gift-card brand');
        $region = $this->boundedOptional($region, 64, 'Gift-card region');
        $faceCurrency = strtoupper(trim($faceCurrency));
        if (preg_match('/\A[A-Z]{3}\z/', $faceCurrency) !== 1) {
            throw new DomainException('Gift-card face currency must be a three-letter currency code.');
        }
        if (! in_array($submissionMode, self::SUBMISSION_MODES, true)) {
            throw new DomainException('Gift-card submission mode is invalid.');
        }
        if (! in_array($verificationMode, self::VERIFICATION_MODES, true)) {
            throw new DomainException('Gift-card verification mode is invalid.');
        }
        if ($verificationMode === 'automatic_with_manual_approval_above_limit') {
            if ($manualApprovalLimitFaceValue === null || $manualApprovalLimitFaceValue < 1) {
                throw new DomainException('Gift-card manual approval threshold must be positive for the selected verification mode.');
            }
        } elseif ($manualApprovalLimitFaceValue !== null) {
            throw new DomainException('Gift-card manual approval threshold is only valid for the threshold verification mode.');
        }
        $this->assertToken($providerCode, 'Gift-card provider code', 2, 64);

        $configurationHash = $this->configurationHash(
            $typeCode,
            $displayName,
            $brand,
            $region,
            $faceCurrency,
            $submissionMode,
            $verificationMode,
            $manualApprovalLimitFaceValue,
            $providerCode,
        );

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $typeCode,
            $displayName,
            $brand,
            $region,
            $faceCurrency,
            $submissionMode,
            $verificationMode,
            $manualApprovalLimitFaceValue,
            $providerCode,
            $configurationHash,
        ): GiftCardTypeReceipt {
            $existing = $connection->table('gift_card_types')->where('type_code', $typeCode)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals(strtolower((string) $existing->configuration_hash), $configurationHash)) {
                    throw new RuntimeException('Gift-card type code conflicts with accepted configuration.');
                }

                return $this->receipt($existing, true);
            }

            $now = $this->timestamp();
            $id = (int) $connection->table('gift_card_types')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'type_code' => $typeCode,
                'display_name' => $displayName,
                'brand' => $brand,
                'region' => $region,
                'face_currency' => $faceCurrency,
                'submission_mode' => $submissionMode,
                'verification_mode' => $verificationMode,
                'manual_approval_limit_face_value' => $manualApprovalLimitFaceValue,
                'provider_code' => $providerCode,
                'active' => true,
                'version' => 1,
                'configuration_hash' => $configurationHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = $connection->table('gift_card_types')->where('id', $id)->first();
            if ($row === null) {
                throw new RuntimeException('Gift-card type persistence failed.');
            }

            return $this->receipt($row, false);
        }, 3);
    }

    public function setActive(string $typeCode, bool $active): GiftCardTypeReceipt
    {
        $this->assertToken($typeCode, 'Gift-card type code', 2, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($typeCode, $active): GiftCardTypeReceipt {
            $row = $connection->table('gift_card_types')->where('type_code', $typeCode)->lockForUpdate()->first();
            if ($row === null) {
                throw new DomainException('Gift-card type does not exist.');
            }
            if ((bool) $row->active !== $active) {
                $connection->table('gift_card_types')->where('id', $row->id)->update([
                    'active' => $active,
                    'updated_at' => $this->timestamp(),
                ]);
                $row = $connection->table('gift_card_types')->where('id', $row->id)->first();
                if ($row === null) {
                    throw new RuntimeException('Gift-card type disappeared after state change.');
                }
            }

            return $this->receipt($row, true);
        }, 3);
    }

    private function configurationHash(
        string $typeCode,
        string $displayName,
        string $brand,
        ?string $region,
        string $faceCurrency,
        string $submissionMode,
        string $verificationMode,
        ?int $manualApprovalLimitFaceValue,
        string $providerCode,
    ): string {
        return hash('sha256', json_encode([
            'type_code' => $typeCode,
            'display_name' => $displayName,
            'brand' => $brand,
            'region' => $region,
            'face_currency' => $faceCurrency,
            'submission_mode' => $submissionMode,
            'verification_mode' => $verificationMode,
            'manual_approval_limit_face_value' => $manualApprovalLimitFaceValue,
            'provider_code' => $providerCode,
            'version' => 1,
        ], JSON_THROW_ON_ERROR));
    }

    private function receipt(object $row, bool $replayed): GiftCardTypeReceipt
    {
        return new GiftCardTypeReceipt(
            (int) $row->id,
            (string) $row->public_id,
            (string) $row->type_code,
            (string) $row->brand,
            $row->region === null ? null : (string) $row->region,
            (string) $row->face_currency,
            (string) $row->submission_mode,
            (string) $row->verification_mode,
            $row->manual_approval_limit_face_value === null ? null : (int) $row->manual_approval_limit_face_value,
            (string) $row->provider_code,
            (int) $row->version,
            (string) $row->configuration_hash,
            (bool) $row->active,
            $replayed,
        );
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
        if ($value === null) {
            return null;
        }

        return $this->bounded($value, $maximum, $label);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}