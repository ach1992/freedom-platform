<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class CardToCardDestinationService
{
    public function __construct(
        private DatabaseManager $database,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    /** @requirement C2C-001 C2C-004 DAT-002 DAT-004 SEC-002 QUA-001 */
    public function register(
        string $code,
        string $cardNumber,
        ?string $accountHolderName,
        bool $adjustmentEnabled,
        ?int $adjustmentMinIrr,
        ?int $adjustmentMaxIrr,
        ?int $reservationMinutes,
        ?int $lateReviewMinutes,
        ?int $dailyLimitIrr,
        int $priority,
        string $verificationProviderCode,
        string $reason,
        string $correlationId,
    ): CardToCardDestinationReceipt {
        $this->assertToken($code, 'Card-to-card destination code', 2, 64);
        $this->assertToken($verificationProviderCode, 'Card-to-card verification provider code', 2, 64);
        $this->assertToken($correlationId, 'Card-to-card destination correlation ID', 8, 64);
        if ($priority < 0 || $priority > 65535) {
            throw new DomainException('Card-to-card destination priority must be between 0 and 65535.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new DomainException('Card-to-card destination reason is required and bounded.');
        }

        $card = $this->normalizeCardNumber($cardNumber);
        $holder = $accountHolderName === null ? null : trim($accountHolderName);
        if ($holder !== null && ($holder === '' || mb_strlen($holder) > 128)) {
            throw new DomainException('Card-to-card account holder name is invalid.');
        }
        [$minimum, $maximum] = $this->adjustmentBounds($adjustmentEnabled, $adjustmentMinIrr, $adjustmentMaxIrr);
        $reservation = $this->boundedConfigInt($reservationMinutes, 'payments.card_to_card.reservation_minutes', 30, 1, 1440);
        $lateReview = $this->boundedConfigInt($lateReviewMinutes, 'payments.card_to_card.late_review_minutes', 1440, $reservation, 10080);
        if ($dailyLimitIrr !== null && $dailyLimitIrr < 1) {
            throw new DomainException('Card-to-card daily limit must be positive integer IRR.');
        }
        $lookupHash = hash_hmac('sha256', $card, $this->lookupKey());
        $configurationHash = $this->configurationHash(
            $code,
            $lookupHash,
            $adjustmentEnabled,
            $minimum,
            $maximum,
            $reservation,
            $lateReview,
            $dailyLimitIrr,
            $priority,
            $verificationProviderCode,
        );

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $code,
            $card,
            $holder,
            $lookupHash,
            $adjustmentEnabled,
            $minimum,
            $maximum,
            $reservation,
            $lateReview,
            $dailyLimitIrr,
            $priority,
            $verificationProviderCode,
            $reason,
            $correlationId,
            $configurationHash,
        ): CardToCardDestinationReceipt {
            $existing = $connection->table('c2c_destination_accounts')->where('code', $code)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->card_lookup_hash, $lookupHash)
                    || ! hash_equals($this->configurationHashFromRow($existing), $configurationHash)) {
                    throw new RuntimeException('Card-to-card destination code conflicts with accepted configuration.');
                }

                return $this->receipt($existing, true);
            }
            if ($connection->table('c2c_destination_accounts')->where('card_lookup_hash', $lookupHash)->exists()) {
                throw new RuntimeException('Card-to-card destination card is already registered under another code.');
            }

            $now = $this->timestamp();
            $destinationId = (int) $connection->table('c2c_destination_accounts')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'encrypted_card_number' => $this->encrypter->encryptString($card),
                'card_lookup_hash' => $lookupHash,
                'masked_card_number' => substr($card, 0, 6).'******'.substr($card, -4),
                'encrypted_account_holder_name' => $holder === null ? null : $this->encrypter->encryptString($holder),
                'state' => 'active',
                'adjustment_enabled' => $adjustmentEnabled,
                'adjustment_min_irr' => $minimum,
                'adjustment_max_irr' => $maximum,
                'reservation_minutes' => $reservation,
                'late_review_minutes' => $lateReview,
                'daily_limit_irr' => $dailyLimitIrr,
                'priority' => $priority,
                'verification_provider_code' => $verificationProviderCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = $connection->table('c2c_destination_accounts')->where('id', $destinationId)->first();
            if ($row === null) {
                throw new RuntimeException('Card-to-card destination persistence failed.');
            }
            $this->recordEvent($connection, $destinationId, 'created', $configurationHash, $reason, $correlationId);

            return $this->receipt($row, false);
        }, 3);
    }

    /** @requirement C2C-001 C2C-004 DAT-003 DAT-004 SEC-002 */
    public function setState(string $publicId, bool $active, string $reason, string $correlationId): CardToCardDestinationReceipt
    {
        if (! Str::isUlid($publicId)) {
            throw new DomainException('Card-to-card destination public ID is invalid.');
        }
        $this->assertToken($correlationId, 'Card-to-card destination correlation ID', 8, 64);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new DomainException('Card-to-card destination state reason is required and bounded.');
        }
        $target = $active ? 'active' : 'inactive';

        return $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $target, $reason, $correlationId): CardToCardDestinationReceipt {
            $row = $connection->table('c2c_destination_accounts')->where('public_id', $publicId)->lockForUpdate()->first();
            if ($row === null) {
                throw new DomainException('Card-to-card destination does not exist.');
            }
            if ($row->state === $target) {
                return $this->receipt($row, true);
            }
            $connection->table('c2c_destination_accounts')->where('id', $row->id)->update([
                'state' => $target,
                'updated_at' => $this->timestamp(),
            ]);
            $fresh = $connection->table('c2c_destination_accounts')->where('id', $row->id)->first();
            if ($fresh === null) {
                throw new RuntimeException('Card-to-card destination disappeared after state change.');
            }
            $this->recordEvent(
                $connection,
                (int) $row->id,
                $target === 'active' ? 'activated' : 'deactivated',
                $this->configurationHashFromRow($fresh),
                $reason,
                $correlationId,
            );

            return $this->receipt($fresh, false);
        }, 3);
    }

    private function normalizeCardNumber(string $value): string
    {
        $digits = preg_replace('/[\s-]+/', '', trim($value));
        if (! is_string($digits) || preg_match('/\A[0-9]{16}\z/', $digits) !== 1 || ! $this->passesLuhn($digits)) {
            throw new DomainException('Card-to-card destination card number is invalid.');
        }

        return $digits;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $parity = strlen($digits) % 2;
        foreach (str_split($digits) as $index => $character) {
            $value = (int) $character;
            if ($index % 2 === $parity) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }

        return $sum % 10 === 0;
    }

    /** @return array{0:int,1:int} */
    private function adjustmentBounds(bool $enabled, ?int $minimum, ?int $maximum): array
    {
        if (! $enabled) {
            return [0, 0];
        }
        $min = $this->boundedConfigInt($minimum, 'payments.card_to_card.adjustment_min_irr', 1000, 0, 999999);
        $max = $this->boundedConfigInt($maximum, 'payments.card_to_card.adjustment_max_irr', 9990, $min, 999999);

        return [$min, $max];
    }

    private function boundedConfigInt(?int $explicit, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $explicit ?? filter_var(config($key, $default), FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new DomainException('Card-to-card numeric configuration is invalid.');
        }

        return $value;
    }

    private function lookupKey(): string
    {
        $key = config('payments.card_to_card.lookup_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Card-to-card lookup key is not configured securely.');
        }

        return $key;
    }

    private function configurationHash(
        string $code,
        string $lookupHash,
        bool $adjustmentEnabled,
        int $minimum,
        int $maximum,
        int $reservationMinutes,
        int $lateReviewMinutes,
        ?int $dailyLimitIrr,
        int $priority,
        string $providerCode,
    ): string {
        return hash('sha256', json_encode([
            'code' => $code,
            'card_lookup_hash' => $lookupHash,
            'state' => 'active',
            'adjustment_enabled' => $adjustmentEnabled,
            'adjustment_min_irr' => $minimum,
            'adjustment_max_irr' => $maximum,
            'reservation_minutes' => $reservationMinutes,
            'late_review_minutes' => $lateReviewMinutes,
            'daily_limit_irr' => $dailyLimitIrr,
            'priority' => $priority,
            'verification_provider_code' => $providerCode,
        ], JSON_THROW_ON_ERROR));
    }

    private function configurationHashFromRow(stdClass $row): string
    {
        return hash('sha256', json_encode([
            'code' => $row->code,
            'card_lookup_hash' => $row->card_lookup_hash,
            'state' => $row->state,
            'adjustment_enabled' => (bool) $row->adjustment_enabled,
            'adjustment_min_irr' => (int) $row->adjustment_min_irr,
            'adjustment_max_irr' => (int) $row->adjustment_max_irr,
            'reservation_minutes' => (int) $row->reservation_minutes,
            'late_review_minutes' => (int) $row->late_review_minutes,
            'daily_limit_irr' => $row->daily_limit_irr === null ? null : (int) $row->daily_limit_irr,
            'priority' => (int) $row->priority,
            'verification_provider_code' => $row->verification_provider_code,
        ], JSON_THROW_ON_ERROR));
    }

    private function recordEvent(Connection $connection, int $destinationId, string $type, string $hash, string $reason, string $correlationId): void
    {
        $connection->table('c2c_destination_account_events')->insert([
            'c2c_destination_account_id' => $destinationId,
            'event_type' => $type,
            'configuration_hash' => $hash,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function receipt(stdClass $row, bool $replayed): CardToCardDestinationReceipt
    {
        return new CardToCardDestinationReceipt(
            (int) $row->id,
            $row->public_id,
            $row->code,
            $row->masked_card_number,
            $row->state,
            (bool) $row->adjustment_enabled,
            (int) $row->adjustment_min_irr,
            (int) $row->adjustment_max_irr,
            (int) $row->reservation_minutes,
            (int) $row->late_review_minutes,
            $row->daily_limit_irr === null ? null : (int) $row->daily_limit_irr,
            (int) $row->priority,
            $row->verification_provider_code,
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

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
