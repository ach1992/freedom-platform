<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type QuoteRow object{
 *     id:int|string,
 *     public_id:string,
 *     quote_key:string,
 *     request_payload_hash:string,
 *     user_id:int|string,
 *     account_type_snapshot:string,
 *     plan_offering_id:int|string,
 *     offering_code_snapshot:string,
 *     offering_version:int|string,
 *     offering_configuration_hash:string,
 *     offering_discount_eligible:int|bool|string,
 *     base_price_irr:int|string,
 *     override_source:string,
 *     override_reference_code:string|null,
 *     override_price_irr:int|string|null,
 *     effective_price_irr:int|string,
 *     discount_reference_code:string|null,
 *     discount_irr:int|string,
 *     final_price_irr:int|string,
 *     currency:string,
 *     configuration_snapshot_hash:string,
 *     valid_from:string,
 *     expires_at:string
 * }
 */
final readonly class QuoteService
{
    private const FORMULA_VERSION = 'buy-002-v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement BUY-002 DAT-002 DAT-003 DAT-004 QUA-001 */
    public function create(
        string $quoteKey,
        int $userId,
        int $planOfferingId,
        QuotePricingInput $pricing,
        string $correlationId,
    ): QuoteReceipt {
        $this->assertToken($quoteKey, 'Quote key', 8, 128);
        $this->assertPositiveId($userId, 'Quote user ID');
        $this->assertPositiveId($planOfferingId, 'Quote plan offering ID');
        $this->assertToken($correlationId, 'Quote correlation ID', 8, 64);

        $expiresAt = $pricing->expiresAt->setTimezone(new DateTimeZone('UTC'));
        $requestPayloadHash = $this->requestPayloadHash($userId, $planOfferingId, $pricing, $expiresAt);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $quoteKey,
                $userId,
                $planOfferingId,
                $pricing,
                $correlationId,
                $expiresAt,
                $requestPayloadHash,
            ): QuoteReceipt {
                $existing = $this->quoteByKey($connection, $quoteKey, true);
                if ($existing !== null) {
                    return $this->quoteReceipt($existing, $requestPayloadHash, true);
                }

                $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
                if ($expiresAt <= $now) {
                    throw new DomainException('Quote expiry must be in the future.');
                }

                /** @var object{account_type:string,account_status:string}|null $user */
                $user = $connection->table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first(['account_type', 'account_status']);
                if ($user === null
                    || $user->account_status !== 'active'
                    || ! in_array($user->account_type, ['customer', 'agent'], true)) {
                    throw new DomainException('Quote requires an active customer or agent account.');
                }

                /** @var object{id:int|string,code:string,version:int|string,base_price_irr:int|string,discount_eligible:int|bool,state:string,visibility:string}|null $offering */
                $offering = $connection->table('plan_offerings')
                    ->where('id', $planOfferingId)
                    ->lockForUpdate()
                    ->first(['id', 'code', 'version', 'base_price_irr', 'discount_eligible', 'state', 'visibility']);
                if ($offering === null || $offering->state !== 'active' || $offering->visibility !== 'visible') {
                    throw new DomainException('Quote requires an active visible plan offering.');
                }

                $offeringVersion = $this->positiveDatabaseInt($offering->version, 'Quote offering version');
                $basePriceIrr = $this->nonNegativeDatabaseInt($offering->base_price_irr, 'Quote base price');
                $offeringDiscountEligible = (bool) $offering->discount_eligible;
                $offeringConfigurationHash = $this->offeringConfigurationHash(
                    $connection,
                    $planOfferingId,
                    $offeringVersion,
                );

                $this->validateOverrideReference(
                    $connection,
                    $userId,
                    $user->account_type,
                    $pricing,
                );
                if ($pricing->discountIrr > 0 && ! $offeringDiscountEligible) {
                    throw new DomainException('Plan offering does not allow a quote discount.');
                }

                $effectivePriceIrr = $pricing->overridePriceIrr ?? $basePriceIrr;
                if ($pricing->discountIrr > $effectivePriceIrr) {
                    throw new DomainException('Quote discount cannot exceed the effective price.');
                }
                $finalPriceIrr = $effectivePriceIrr - $pricing->discountIrr;

                $snapshot = [
                    'account_type' => $user->account_type,
                    'base_price_irr' => $basePriceIrr,
                    'currency' => 'IRR',
                    'discount_irr' => $pricing->discountIrr,
                    'discount_reference_code' => $pricing->discountReferenceCode,
                    'effective_price_irr' => $effectivePriceIrr,
                    'final_price_irr' => $finalPriceIrr,
                    'formula_version' => self::FORMULA_VERSION,
                    'offering_code' => $offering->code,
                    'offering_configuration_hash' => $offeringConfigurationHash,
                    'offering_discount_eligible' => $offeringDiscountEligible,
                    'offering_id' => $planOfferingId,
                    'offering_version' => $offeringVersion,
                    'override_price_irr' => $pricing->overridePriceIrr,
                    'override_reference_code' => $pricing->overrideReferenceCode,
                    'override_source' => $pricing->overrideSource->value,
                ];
                ksort($snapshot, SORT_STRING);
                $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (strlen($snapshotJson) > 8192) {
                    throw new RuntimeException('Quote configuration snapshot exceeds the storage boundary.');
                }
                $snapshotHash = hash('sha256', $snapshotJson);
                $nowString = $this->databaseDateTime($now);

                $quoteId = (int) $connection->table('quotes')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'quote_key' => $quoteKey,
                    'request_payload_hash' => $requestPayloadHash,
                    'user_id' => $userId,
                    'account_type_snapshot' => $user->account_type,
                    'plan_offering_id' => $planOfferingId,
                    'offering_code_snapshot' => $offering->code,
                    'offering_version' => $offeringVersion,
                    'offering_configuration_hash' => $offeringConfigurationHash,
                    'offering_discount_eligible' => $offeringDiscountEligible,
                    'base_price_irr' => $basePriceIrr,
                    'override_source' => $pricing->overrideSource->value,
                    'override_reference_code' => $pricing->overrideReferenceCode,
                    'override_price_irr' => $pricing->overridePriceIrr,
                    'effective_price_irr' => $effectivePriceIrr,
                    'discount_reference_code' => $pricing->discountReferenceCode,
                    'discount_irr' => $pricing->discountIrr,
                    'final_price_irr' => $finalPriceIrr,
                    'currency' => 'IRR',
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => $snapshotHash,
                    'creation_correlation_id' => $correlationId,
                    'valid_from' => $nowString,
                    'expires_at' => $this->databaseDateTime($expiresAt),
                    'created_at' => $nowString,
                ]);

                $created = $this->quoteById($connection, $quoteId);
                if ($created === null) {
                    throw new RuntimeException('Quote persistence failed.');
                }

                return $this->quoteReceipt($created, $requestPayloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->quoteByKey($this->database->connection(), $quoteKey);
            if ($existing !== null) {
                return $this->quoteReceipt($existing, $requestPayloadHash, true);
            }

            throw $exception;
        }
    }

    /** @requirement BUY-002 DAT-004 QUA-001 */
    public function current(string $quotePublicId): QuoteReceipt
    {
        $this->assertUlid($quotePublicId, 'Quote public ID');
        $connection = $this->database->connection();
        /** @var QuoteRow|null $row */
        $row = $connection->table('quotes')
            ->where('public_id', $quotePublicId)
            ->first($this->quoteColumns());
        if ($row === null) {
            throw new DomainException('Quote does not exist.');
        }

        $receipt = $this->quoteReceipt($row, $row->request_payload_hash, true);
        if ($receipt->isExpiredAt($this->clock->now()->setTimezone(new DateTimeZone('UTC')))) {
            throw new RuntimeException('Quote has expired.');
        }

        return $receipt;
    }

    /** @return QuoteRow|null */
    private function quoteByKey(Connection $connection, string $quoteKey, bool $lock = false): ?object
    {
        $query = $connection->table('quotes')->where('quote_key', $quoteKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var QuoteRow|null $row */
        $row = $query->first($this->quoteColumns());

        return $row;
    }

    /** @return QuoteRow|null */
    private function quoteById(Connection $connection, int $quoteId): ?object
    {
        /** @var QuoteRow|null $row */
        $row = $connection->table('quotes')->where('id', $quoteId)->first($this->quoteColumns());

        return $row;
    }

    /** @return list<string> */
    private function quoteColumns(): array
    {
        return [
            'id', 'public_id', 'quote_key', 'request_payload_hash', 'user_id', 'account_type_snapshot',
            'plan_offering_id', 'offering_code_snapshot', 'offering_version', 'offering_configuration_hash',
            'offering_discount_eligible', 'base_price_irr', 'override_source', 'override_reference_code',
            'override_price_irr', 'effective_price_irr', 'discount_reference_code', 'discount_irr',
            'final_price_irr', 'currency', 'configuration_snapshot_hash', 'valid_from', 'expires_at',
        ];
    }

    /** @param QuoteRow $row */
    private function quoteReceipt(object $row, string $requestPayloadHash, bool $replayed): QuoteReceipt
    {
        if (! hash_equals($row->request_payload_hash, $requestPayloadHash)) {
            throw new RuntimeException('Quote key conflict.');
        }
        $overrideSource = QuoteOverrideSource::tryFrom($row->override_source)
            ?? throw new RuntimeException('Stored quote override source is invalid.');

        return new QuoteReceipt(
            $this->positiveDatabaseInt($row->id, 'Quote ID'),
            $row->public_id,
            $row->quote_key,
            $this->positiveDatabaseInt($row->user_id, 'Quote user ID'),
            $row->account_type_snapshot,
            $this->positiveDatabaseInt($row->plan_offering_id, 'Quote offering ID'),
            $row->offering_code_snapshot,
            $this->positiveDatabaseInt($row->offering_version, 'Quote offering version'),
            $row->offering_configuration_hash,
            (bool) $row->offering_discount_eligible,
            $this->nonNegativeDatabaseInt($row->base_price_irr, 'Quote base price'),
            $overrideSource,
            $row->override_reference_code,
            $row->override_price_irr === null ? null : $this->nonNegativeDatabaseInt($row->override_price_irr, 'Quote override price'),
            $this->nonNegativeDatabaseInt($row->effective_price_irr, 'Quote effective price'),
            $row->discount_reference_code,
            $this->nonNegativeDatabaseInt($row->discount_irr, 'Quote discount'),
            $this->nonNegativeDatabaseInt($row->final_price_irr, 'Quote final price'),
            $row->currency,
            $row->configuration_snapshot_hash,
            $this->databaseDateTimeFromString($row->valid_from, 'Quote valid-from'),
            $this->databaseDateTimeFromString($row->expires_at, 'Quote expiry'),
            $replayed,
        );
    }

    private function offeringConfigurationHash(Connection $connection, int $offeringId, int $version): string
    {
        /** @var string|null $hash */
        $hash = $connection->table('plan_offering_histories')
            ->where('plan_offering_id', $offeringId)
            ->where('version', $version)
            ->lockForUpdate()
            ->value('to_configuration_hash');
        if ($hash === null || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new RuntimeException('Plan offering configuration snapshot is unavailable.');
        }

        return $hash;
    }

    private function validateOverrideReference(
        Connection $connection,
        int $userId,
        string $accountType,
        QuotePricingInput $pricing,
    ): void {
        if ($pricing->overrideSource === QuoteOverrideSource::Agent) {
            if ($accountType !== 'agent') {
                throw new DomainException('Agent quote override requires an agent account.');
            }
            $matches = $connection->table('agent_profiles')
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->where('pricing_profile_code', $pricing->overrideReferenceCode)
                ->lockForUpdate()
                ->count();
            if ($matches !== 1) {
                throw new DomainException('Agent quote override reference is not current.');
            }
        }

        if ($pricing->overrideSource === QuoteOverrideSource::Tier) {
            $matches = $connection->table('customer_profiles as p')
                ->join('customer_tiers as t', 't.id', '=', 'p.current_tier_id')
                ->where('p.user_id', $userId)
                ->where('t.is_active', true)
                ->where('t.code', $pricing->overrideReferenceCode)
                ->lockForUpdate()
                ->count();
            if ($matches !== 1) {
                throw new DomainException('Tier quote override reference is not current.');
            }
        }
    }

    private function requestPayloadHash(
        int $userId,
        int $planOfferingId,
        QuotePricingInput $pricing,
        DateTimeImmutable $expiresAt,
    ): string {
        return hash('sha256', json_encode([
            'user_id' => $userId,
            'plan_offering_id' => $planOfferingId,
            'override_source' => $pricing->overrideSource->value,
            'override_reference_code' => $pricing->overrideReferenceCode,
            'override_price_irr' => $pricing->overridePriceIrr,
            'discount_reference_code' => $pricing->discountReferenceCode,
            'discount_irr' => $pricing->discountIrr,
            'expires_at' => $this->databaseDateTime($expiresAt),
        ], JSON_THROW_ON_ERROR));
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException($label.' must be positive.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (strlen($value) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveDatabaseInt(int|string $value, string $label): int
    {
        $integer = $this->databaseInt($value, $label);
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(int|string $value, string $label): int
    {
        $integer = $this->databaseInt($value, $label);
        if ($integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function databaseInt(int|string $value, string $label): int
    {
        if (is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function databaseDateTimeFromString(string $value, string $label): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $parsed;
    }
}
