<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\PromotionUsageReservationState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type ResolutionRow object{
 *     id:int|string, public_id:string, user_id:int|string, plan_offering_id:int|string,
 *     input_price_irr:int|string, pricing_rule_id:int|string|null, pricing_rule_version_id:int|string|null,
 *     rule_code_snapshot:string|null, rule_kind_snapshot:string|null, rule_version:int|string|null,
 *     rule_configuration_hash:string|null, discount_irr:int|string, configuration_snapshot:string,
 *     configuration_snapshot_hash:string
 * }
 * @phpstan-type VersionRow object{
 *     id:int|string, pricing_rule_id:int|string, version:int|string, total_use_limit:int|string|null,
 *     per_user_use_limit:int|string|null, allows_free_order:int|bool|string,
 *     configuration_snapshot:string, configuration_hash:string
 * }
 * @phpstan-type RuleRow object{id:int|string,rule_code:string,kind:string}
 * @phpstan-type QuoteRow object{
 *     id:int|string, public_id:string, user_id:int|string, plan_offering_id:int|string,
 *     offering_discount_eligible:int|bool|string, effective_price_irr:int|string,
 *     discount_reference_code:string|null, discount_irr:int|string, final_price_irr:int|string,
 *     currency:string, configuration_snapshot:string, configuration_snapshot_hash:string,
 *     valid_from:string, expires_at:string
 * }
 * @phpstan-type ReservationRow object{
 *     id:int|string, public_id:string, reservation_key:string, request_payload_hash:string,
 *     pricing_rule_resolution_id:int|string, resolution_public_id_snapshot:string,
 *     quote_id:int|string, quote_public_id_snapshot:string, user_id:int|string,
 *     plan_offering_id:int|string, pricing_rule_id:int|string, pricing_rule_version_id:int|string,
 *     rule_code_snapshot:string, rule_version:int|string, rule_configuration_hash:string,
 *     discount_irr:int|string, configuration_snapshot_hash:string, release_id:int|string|null
 * }
 * @phpstan-type ReleaseRow object{
 *     id:int|string, public_id:string, release_key:string, request_payload_hash:string,
 *     promotion_usage_reservation_id:int|string, released_by_user_id:int|string,
 *     reservation_public_id:string, user_id:int|string
 * }
 */
final readonly class PromotionUsageReservationService
{
    private const RESERVATION_SNAPSHOT_VERSION = 'pro-001-reservation-v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement PRO-001 BUY-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 */
    public function reserve(
        string $reservationKey,
        string $resolutionPublicId,
        string $quotePublicId,
        PromotionUsageContext $context,
    ): PromotionUsageReservationReceipt {
        $this->assertKey($reservationKey, 'Promotion reservation key');
        $this->assertUlid($resolutionPublicId, 'Promotion resolution public ID');
        $this->assertUlid($quotePublicId, 'Quote public ID');

        $connection = $this->database->connection();
        $resolution = $this->resolutionByPublicId($connection, $resolutionPublicId);
        if ($resolution === null) {
            throw new DomainException('Promotion resolution does not exist.');
        }
        $this->assertActorOwnsUser($context, $this->positiveDatabaseInt($resolution->user_id, 'Promotion resolution user ID'));

        $payloadHash = $this->reservationPayloadHash($resolutionPublicId, $quotePublicId);
        $existing = $this->reservationByKey($connection, $reservationKey);
        if ($existing !== null) {
            return $this->reservationReplayReceipt($existing, $payloadHash, $context);
        }

        try {
            return $connection->transaction(function (Connection $connection) use (
                $reservationKey,
                $resolutionPublicId,
                $quotePublicId,
                $context,
                $payloadHash,
            ): PromotionUsageReservationReceipt {
                $resolution = $this->resolutionByPublicId($connection, $resolutionPublicId, true);
                if ($resolution === null) {
                    throw new DomainException('Promotion resolution does not exist.');
                }
                $userId = $this->positiveDatabaseInt($resolution->user_id, 'Promotion resolution user ID');
                $this->assertActorOwnsUser($context, $userId);
                $this->lockActivePricingSubject($connection, $userId);

                $versionId = $this->matchedPromotionVersionId($resolution);
                $ruleId = $this->positiveDatabaseInt($resolution->pricing_rule_id, 'Promotion resolution rule ID');
                $rule = $this->ruleById($connection, $ruleId, true);
                if ($rule === null) {
                    throw new RuntimeException('Promotion resolution rule no longer exists.');
                }
                $version = $this->versionById($connection, $versionId, true);
                if ($version === null) {
                    throw new RuntimeException('Promotion resolution rule version no longer exists.');
                }
                $this->assertResolutionIdentity($resolution, $rule, $version);

                $existing = $this->reservationByKey($connection, $reservationKey, true);
                if ($existing !== null) {
                    return $this->reservationReplayReceipt($existing, $payloadHash, $context);
                }

                $quote = $this->quoteByPublicId($connection, $quotePublicId);
                if ($quote === null) {
                    throw new DomainException('Promotion reservation quote does not exist.');
                }
                $this->assertQuoteBinding($quote, $resolution, $rule, $version);

                $resolutionId = $this->positiveDatabaseInt($resolution->id, 'Promotion resolution ID');
                $quoteId = $this->positiveDatabaseInt($quote->id, 'Quote ID');
                $claimed = $connection->table('promotion_usage_reservations')
                    ->where(function (Builder $query) use ($resolutionId, $quoteId): void {
                        $query->where('pricing_rule_resolution_id', $resolutionId)
                            ->orWhere('quote_id', $quoteId);
                    })
                    ->lockForUpdate()
                    ->first(['id', 'reservation_key']);
                if ($claimed !== null) {
                    throw new RuntimeException('Promotion resolution or quote is already reserved.');
                }

                $activeReservations = $connection->table('promotion_usage_reservations as reservation')
                    ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id')
                    ->where('reservation.pricing_rule_id', $ruleId)
                    ->whereNull('release.id')
                    ->get(['reservation.id', 'reservation.user_id']);

                $totalLimit = $version->total_use_limit === null
                    ? null
                    : $this->positiveDatabaseInt($version->total_use_limit, 'Promotion total use limit');
                $perUserLimit = $version->per_user_use_limit === null
                    ? null
                    : $this->positiveDatabaseInt($version->per_user_use_limit, 'Promotion per-user use limit');
                if ($totalLimit !== null && $activeReservations->count() >= $totalLimit) {
                    throw new DomainException('Promotion global usage capacity is exhausted.');
                }
                if ($perUserLimit !== null) {
                    $userActive = $activeReservations->filter(
                        static fn (object $row): bool => (int) $row->user_id === $userId,
                    )->count();
                    if ($userActive >= $perUserLimit) {
                        throw new DomainException('Promotion per-user usage capacity is exhausted.');
                    }
                }

                $discountIrr = $this->positiveDatabaseInt($resolution->discount_irr, 'Promotion discount');
                $planOfferingId = $this->positiveDatabaseInt($resolution->plan_offering_id, 'Promotion offering ID');
                $ruleVersion = $this->positiveDatabaseInt($version->version, 'Promotion rule version');
                $snapshot = [
                    'discount_irr' => $discountIrr,
                    'formula_version' => self::RESERVATION_SNAPSHOT_VERSION,
                    'limits' => [
                        'per_user_use_limit' => $perUserLimit,
                        'total_use_limit' => $totalLimit,
                    ],
                    'plan_offering_id' => $planOfferingId,
                    'quote' => [
                        'configuration_snapshot_hash' => $quote->configuration_snapshot_hash,
                        'public_id' => $quote->public_id,
                    ],
                    'resolution' => [
                        'configuration_snapshot_hash' => $resolution->configuration_snapshot_hash,
                        'public_id' => $resolution->public_id,
                    ],
                    'rule' => [
                        'code' => $rule->rule_code,
                        'configuration_hash' => $version->configuration_hash,
                        'id' => $ruleId,
                        'version' => $ruleVersion,
                        'version_id' => $versionId,
                    ],
                    'user_id' => $userId,
                ];
                ksort($snapshot, SORT_STRING);
                $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (strlen($snapshotJson) > 8192) {
                    throw new RuntimeException('Promotion reservation snapshot exceeds the storage boundary.');
                }
                $snapshotHash = hash('sha256', $snapshotJson);
                $createdAt = $this->timestamp();

                $reservationId = (int) $connection->table('promotion_usage_reservations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'reservation_key' => $reservationKey,
                    'request_payload_hash' => $payloadHash,
                    'pricing_rule_resolution_id' => $resolutionId,
                    'resolution_public_id_snapshot' => $resolution->public_id,
                    'resolution_configuration_snapshot_hash' => $resolution->configuration_snapshot_hash,
                    'quote_id' => $quoteId,
                    'quote_public_id_snapshot' => $quote->public_id,
                    'quote_configuration_snapshot_hash' => $quote->configuration_snapshot_hash,
                    'user_id' => $userId,
                    'plan_offering_id' => $planOfferingId,
                    'pricing_rule_id' => $ruleId,
                    'pricing_rule_version_id' => $versionId,
                    'rule_code_snapshot' => $rule->rule_code,
                    'rule_version' => $ruleVersion,
                    'rule_configuration_hash' => $version->configuration_hash,
                    'discount_irr' => $discountIrr,
                    'total_use_limit_snapshot' => $totalLimit,
                    'per_user_use_limit_snapshot' => $perUserLimit,
                    'allows_free_order_snapshot' => (bool) $version->allows_free_order,
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => $snapshotHash,
                    'created_at' => $createdAt,
                ]);

                $created = $this->reservationById($connection, $reservationId);
                if ($created === null) {
                    throw new RuntimeException('Promotion usage reservation persistence failed.');
                }

                return $this->reservationReceipt($created, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->reservationByKey($this->database->connection(), $reservationKey);
            if ($existing !== null) {
                return $this->reservationReplayReceipt($existing, $payloadHash, $context);
            }

            throw $exception;
        }
    }

    /** @requirement PRO-001 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 */
    public function release(
        string $releaseKey,
        string $reservationPublicId,
        PromotionUsageContext $context,
    ): PromotionUsageReleaseReceipt {
        $this->assertKey($releaseKey, 'Promotion release key');
        $this->assertUlid($reservationPublicId, 'Promotion reservation public ID');

        $connection = $this->database->connection();
        $reservation = $this->reservationByPublicId($connection, $reservationPublicId);
        if ($reservation === null) {
            throw new DomainException('Promotion usage reservation does not exist.');
        }
        $this->assertActorOwnsReservation($context, $reservation);
        $payloadHash = $this->releasePayloadHash($reservationPublicId);

        $existingByKey = $this->releaseByKey($connection, $releaseKey);
        if ($existingByKey !== null) {
            return $this->releaseReplayReceipt($existingByKey, $payloadHash, $reservationPublicId, $context);
        }

        try {
            return $connection->transaction(function (Connection $connection) use (
                $releaseKey,
                $reservationPublicId,
                $context,
                $payloadHash,
                $reservation,
            ): PromotionUsageReleaseReceipt {
                $ruleId = $this->positiveDatabaseInt($reservation->pricing_rule_id, 'Promotion rule ID');
                if ($this->ruleById($connection, $ruleId, true) === null) {
                    throw new RuntimeException('Promotion reservation rule no longer exists.');
                }
                $versionId = $this->positiveDatabaseInt($reservation->pricing_rule_version_id, 'Promotion rule version ID');
                if ($this->versionById($connection, $versionId, true) === null) {
                    throw new RuntimeException('Promotion reservation rule version no longer exists.');
                }

                $locked = $this->reservationByPublicId($connection, $reservationPublicId, true);
                if ($locked === null) {
                    throw new DomainException('Promotion usage reservation does not exist.');
                }
                $this->assertActorOwnsReservation($context, $locked);

                $existingByKey = $this->releaseByKey($connection, $releaseKey, true);
                if ($existingByKey !== null) {
                    return $this->releaseReplayReceipt($existingByKey, $payloadHash, $reservationPublicId, $context);
                }

                $existingForReservation = $this->releaseByReservationId(
                    $connection,
                    $this->positiveDatabaseInt($locked->id, 'Promotion reservation ID'),
                    true,
                );
                if ($existingForReservation !== null) {
                    throw new DomainException('Promotion usage reservation is already released.');
                }

                $releaseId = (int) $connection->table('promotion_usage_releases')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'release_key' => $releaseKey,
                    'request_payload_hash' => $payloadHash,
                    'promotion_usage_reservation_id' => $this->positiveDatabaseInt($locked->id, 'Promotion reservation ID'),
                    'released_by_user_id' => $context->actorUserId,
                    'reservation_configuration_snapshot_hash' => $locked->configuration_snapshot_hash,
                    'created_at' => $this->timestamp(),
                ]);

                $created = $this->releaseById($connection, $releaseId);
                if ($created === null) {
                    throw new RuntimeException('Promotion usage release persistence failed.');
                }

                return $this->releaseReceipt($created, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->releaseByKey($this->database->connection(), $releaseKey);
            if ($existing !== null) {
                return $this->releaseReplayReceipt($existing, $payloadHash, $reservationPublicId, $context);
            }

            throw $exception;
        }
    }

    /** @param ResolutionRow $resolution */
    private function matchedPromotionVersionId(object $resolution): int
    {
        if ($resolution->pricing_rule_id === null
            || $resolution->pricing_rule_version_id === null
            || $resolution->rule_code_snapshot === null
            || $resolution->rule_version === null
            || $resolution->rule_configuration_hash === null
            || (int) $resolution->discount_irr <= 0) {
            throw new DomainException('Promotion usage reservation requires a matched positive-discount resolution.');
        }
        if ($resolution->rule_kind_snapshot !== 'promotion') {
            throw new DomainException('Promotion usage reservation requires a promotion resolution.');
        }

        return $this->positiveDatabaseInt($resolution->pricing_rule_version_id, 'Promotion rule version ID');
    }

    /**
     * @param  ResolutionRow  $resolution
     * @param  RuleRow  $rule
     * @param  VersionRow  $version
     */
    private function assertResolutionIdentity(object $resolution, object $rule, object $version): void
    {
        $ruleId = $this->positiveDatabaseInt($resolution->pricing_rule_id, 'Promotion resolution rule ID');
        $versionId = $this->positiveDatabaseInt($resolution->pricing_rule_version_id, 'Promotion resolution version ID');
        if ($rule->kind !== 'promotion'
            || $ruleId !== (int) $rule->id
            || $ruleId !== (int) $version->pricing_rule_id
            || $versionId !== (int) $version->id
            || ! hash_equals((string) $resolution->rule_code_snapshot, $rule->rule_code)
            || (int) $resolution->rule_version !== (int) $version->version
            || ! hash_equals((string) $resolution->rule_configuration_hash, $version->configuration_hash)) {
            throw new RuntimeException('Promotion resolution immutable rule identity is inconsistent.');
        }
        if (! hash_equals($resolution->configuration_snapshot_hash, hash('sha256', $resolution->configuration_snapshot))) {
            throw new RuntimeException('Promotion resolution snapshot identity is invalid.');
        }
        if (! hash_equals($version->configuration_hash, hash('sha256', $version->configuration_snapshot))) {
            throw new RuntimeException('Promotion rule version snapshot identity is invalid.');
        }
    }

    /**
     * @param  QuoteRow  $quote
     * @param  ResolutionRow  $resolution
     * @param  RuleRow  $rule
     * @param  VersionRow  $version
     */
    private function assertQuoteBinding(object $quote, object $resolution, object $rule, object $version): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        if ((int) $quote->user_id !== (int) $resolution->user_id
            || (int) $quote->plan_offering_id !== (int) $resolution->plan_offering_id
            || ! (bool) $quote->offering_discount_eligible
            || (int) $quote->effective_price_irr !== (int) $resolution->input_price_irr
            || $quote->discount_reference_code === null
            || ! hash_equals($quote->discount_reference_code, $rule->rule_code)
            || (int) $quote->discount_irr !== (int) $resolution->discount_irr
            || $quote->currency !== 'IRR') {
            throw new DomainException('Quote does not match the immutable promotion resolution.');
        }
        if ($this->storedDateTime($quote->valid_from) > $now || $this->storedDateTime($quote->expires_at) <= $now) {
            throw new DomainException('Promotion reservation requires a currently valid quote.');
        }
        if (! hash_equals($quote->configuration_snapshot_hash, hash('sha256', $quote->configuration_snapshot))) {
            throw new RuntimeException('Quote configuration snapshot identity is invalid.');
        }
        if ((int) $quote->final_price_irr === 0 && ! (bool) $version->allows_free_order) {
            throw new DomainException('Promotion reservation cannot authorize a free quote without explicit rule permission.');
        }
    }

    private function lockActivePricingSubject(Connection $connection, int $userId): void
    {
        /** @var object{account_type:string,account_status:string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['account_type', 'account_status']);
        if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
            throw new DomainException('Promotion usage reservation requires an active customer or agent.');
        }
    }

    private function assertActorOwnsUser(PromotionUsageContext $context, int $userId): void
    {
        if ($context->actorUserId !== $userId) {
            throw new AuthorizationException('Promotion usage actor is not authorized for this subject.');
        }
    }

    /** @param ReservationRow $reservation */
    private function assertActorOwnsReservation(PromotionUsageContext $context, object $reservation): void
    {
        $this->assertActorOwnsUser($context, $this->positiveDatabaseInt($reservation->user_id, 'Promotion reservation user ID'));
    }

    private function reservationPayloadHash(string $resolutionPublicId, string $quotePublicId): string
    {
        return hash('sha256', json_encode([
            'operation' => 'reserve',
            'quote_public_id' => $quotePublicId,
            'resolution_public_id' => $resolutionPublicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function releasePayloadHash(string $reservationPublicId): string
    {
        return hash('sha256', json_encode([
            'operation' => 'release',
            'reservation_public_id' => $reservationPublicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return ResolutionRow|null */
    private function resolutionByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('pricing_rule_resolutions')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var ResolutionRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'user_id', 'plan_offering_id', 'input_price_irr', 'pricing_rule_id',
            'pricing_rule_version_id', 'rule_code_snapshot', 'rule_kind_snapshot', 'rule_version',
            'rule_configuration_hash', 'discount_irr', 'configuration_snapshot', 'configuration_snapshot_hash',
        ]);

        return $row;
    }

    /** @return VersionRow|null */
    private function versionById(Connection $connection, int $id, bool $lock = false): ?object
    {
        $query = $connection->table('pricing_rule_versions')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var VersionRow|null $row */
        $row = $query->first([
            'id', 'pricing_rule_id', 'version', 'total_use_limit', 'per_user_use_limit', 'allows_free_order',
            'configuration_snapshot', 'configuration_hash',
        ]);

        return $row;
    }

    /** @return RuleRow|null */
    private function ruleById(Connection $connection, int $id, bool $lock = false): ?object
    {
        $query = $connection->table('pricing_rules')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var RuleRow|null $row */
        $row = $query->first(['id', 'rule_code', 'kind']);

        return $row;
    }

    /** @return QuoteRow|null */
    private function quoteByPublicId(Connection $connection, string $publicId): ?object
    {
        /** @var QuoteRow|null $row */
        $row = $connection->table('quotes')->where('public_id', $publicId)->first([
            'id', 'public_id', 'user_id', 'plan_offering_id', 'offering_discount_eligible', 'effective_price_irr',
            'discount_reference_code', 'discount_irr', 'final_price_irr', 'currency', 'configuration_snapshot',
            'configuration_snapshot_hash', 'valid_from', 'expires_at',
        ]);

        return $row;
    }

    /** @return ReservationRow|null */
    private function reservationByKey(Connection $connection, string $key, bool $lock = false): ?object
    {
        /** @var ReservationRow|null $row */
        $row = $this->reservationQuery($connection, $lock)
            ->where('reservation.reservation_key', $key)
            ->first($this->reservationColumns());

        return $row;
    }

    /** @return ReservationRow|null */
    private function reservationById(Connection $connection, int $id, bool $lock = false): ?object
    {
        /** @var ReservationRow|null $row */
        $row = $this->reservationQuery($connection, $lock)
            ->where('reservation.id', $id)
            ->first($this->reservationColumns());

        return $row;
    }

    /** @return ReservationRow|null */
    private function reservationByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        /** @var ReservationRow|null $row */
        $row = $this->reservationQuery($connection, $lock)
            ->where('reservation.public_id', $publicId)
            ->first($this->reservationColumns());

        return $row;
    }

    private function reservationQuery(Connection $connection, bool $lock): Builder
    {
        $query = $connection->table('promotion_usage_reservations as reservation')
            ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    /** @return list<string> */
    private function reservationColumns(): array
    {
        return [
            'reservation.id as id', 'reservation.public_id as public_id', 'reservation.reservation_key as reservation_key',
            'reservation.request_payload_hash as request_payload_hash',
            'reservation.pricing_rule_resolution_id as pricing_rule_resolution_id',
            'reservation.resolution_public_id_snapshot as resolution_public_id_snapshot',
            'reservation.quote_id as quote_id', 'reservation.quote_public_id_snapshot as quote_public_id_snapshot',
            'reservation.user_id as user_id', 'reservation.plan_offering_id as plan_offering_id',
            'reservation.pricing_rule_id as pricing_rule_id', 'reservation.pricing_rule_version_id as pricing_rule_version_id',
            'reservation.rule_code_snapshot as rule_code_snapshot', 'reservation.rule_version as rule_version',
            'reservation.rule_configuration_hash as rule_configuration_hash', 'reservation.discount_irr as discount_irr',
            'reservation.configuration_snapshot_hash as configuration_snapshot_hash', 'release.id as release_id',
        ];
    }

    /** @return ReleaseRow|null */
    private function releaseByKey(Connection $connection, string $key, bool $lock = false): ?object
    {
        $query = $this->releaseQuery($connection, $lock)->where('release.release_key', $key);
        /** @var ReleaseRow|null $row */
        $row = $query->first($this->releaseColumns());

        return $row;
    }

    /** @return ReleaseRow|null */
    private function releaseByReservationId(Connection $connection, int $reservationId, bool $lock = false): ?object
    {
        $query = $this->releaseQuery($connection, $lock)->where('release.promotion_usage_reservation_id', $reservationId);
        /** @var ReleaseRow|null $row */
        $row = $query->first($this->releaseColumns());

        return $row;
    }

    /** @return ReleaseRow|null */
    private function releaseById(Connection $connection, int $id, bool $lock = false): ?object
    {
        $query = $this->releaseQuery($connection, $lock)->where('release.id', $id);
        /** @var ReleaseRow|null $row */
        $row = $query->first($this->releaseColumns());

        return $row;
    }

    private function releaseQuery(Connection $connection, bool $lock): Builder
    {
        $query = $connection->table('promotion_usage_releases as release')
            ->join('promotion_usage_reservations as reservation', 'reservation.id', '=', 'release.promotion_usage_reservation_id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    /** @return list<string> */
    private function releaseColumns(): array
    {
        return [
            'release.id as id', 'release.public_id as public_id', 'release.release_key as release_key',
            'release.request_payload_hash as request_payload_hash',
            'release.promotion_usage_reservation_id as promotion_usage_reservation_id',
            'release.released_by_user_id as released_by_user_id', 'reservation.public_id as reservation_public_id',
            'reservation.user_id as user_id',
        ];
    }

    /** @param ReservationRow $row */
    private function reservationReplayReceipt(
        object $row,
        string $payloadHash,
        PromotionUsageContext $context,
    ): PromotionUsageReservationReceipt {
        $this->assertActorOwnsReservation($context, $row);
        if (! hash_equals($row->request_payload_hash, $payloadHash)) {
            throw new RuntimeException('Promotion reservation key conflict.');
        }

        return $this->reservationReceipt($row, true);
    }

    /** @param ReservationRow $row */
    private function reservationReceipt(object $row, bool $replayed): PromotionUsageReservationReceipt
    {
        return new PromotionUsageReservationReceipt(
            $this->positiveDatabaseInt($row->id, 'Promotion reservation ID'),
            $row->public_id,
            $row->reservation_key,
            $row->resolution_public_id_snapshot,
            $row->quote_public_id_snapshot,
            $this->positiveDatabaseInt($row->user_id, 'Promotion reservation user ID'),
            $this->positiveDatabaseInt($row->plan_offering_id, 'Promotion reservation offering ID'),
            $row->rule_code_snapshot,
            $this->positiveDatabaseInt($row->rule_version, 'Promotion reservation rule version'),
            $row->rule_configuration_hash,
            $this->positiveDatabaseInt($row->discount_irr, 'Promotion reservation discount'),
            $row->release_id === null ? PromotionUsageReservationState::Active : PromotionUsageReservationState::Released,
            $replayed,
        );
    }

    /** @param ReleaseRow $row */
    private function releaseReplayReceipt(
        object $row,
        string $payloadHash,
        string $reservationPublicId,
        PromotionUsageContext $context,
    ): PromotionUsageReleaseReceipt {
        $this->assertActorOwnsUser($context, $this->positiveDatabaseInt($row->user_id, 'Promotion release user ID'));
        if (! hash_equals($row->request_payload_hash, $payloadHash)
            || ! hash_equals($row->reservation_public_id, $reservationPublicId)) {
            throw new RuntimeException('Promotion release key conflict.');
        }

        return $this->releaseReceipt($row, true);
    }

    /** @param ReleaseRow $row */
    private function releaseReceipt(object $row, bool $replayed): PromotionUsageReleaseReceipt
    {
        return new PromotionUsageReleaseReceipt(
            $this->positiveDatabaseInt($row->id, 'Promotion release ID'),
            $row->public_id,
            $row->release_key,
            $row->reservation_public_id,
            $this->positiveDatabaseInt($row->user_id, 'Promotion release user ID'),
            $replayed,
        );
    }

    private function assertKey(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $value) !== 1) {
            throw new \InvalidArgumentException($label.' is invalid.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new \InvalidArgumentException($label.' is invalid.');
        }
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
