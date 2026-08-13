<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type ReservationRow object{
 *     id:int|string, public_id:string, user_id:int|string, quote_id:int|string,
 *     quote_public_id_snapshot:string, pricing_rule_id:int|string, pricing_rule_version_id:int|string,
 *     discount_irr:int|string, configuration_snapshot_hash:string
 * }
 * @phpstan-type SettlementRow object{
 *     id:int|string, public_id:string, payment_intent_id:int|string, user_id:int|string,
 *     source_quote_id:int|string, source_quote_public_id:string, settled_at:string
 * }
 * @phpstan-type RedemptionRow object{
 *     id:int|string, public_id:string, redemption_key:string, request_payload_hash:string,
 *     promotion_usage_reservation_id:int|string, purchase_settlement_id:int|string,
 *     user_id:int|string, reservation_public_id:string, purchase_settlement_public_id:string,
 *     discount_irr:int|string, created_at:string
 * }
 * @phpstan-type IntentRow object{id:int|string,purpose:string,user_id:int|string,source_quote_id:int|string|null,source_quote_public_id:string|null,state:string}
 * @phpstan-type QuoteExpiryRow object{id:int|string,expires_at:string}
 */
final readonly class PromotionUsageFinalizationService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private PromotionUsageReservationService $reservationService,
    ) {}

    /** @requirement PRO-001 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function finalize(
        string $redemptionKey,
        string $reservationPublicId,
        string $purchaseSettlementPublicId,
        PromotionUsageContext $context,
    ): PromotionUsageFinalizationReceipt {
        $this->assertKey($redemptionKey, 'Promotion redemption key');
        $this->assertUlid($reservationPublicId, 'Promotion reservation public ID');
        $this->assertUlid($purchaseSettlementPublicId, 'Purchase settlement public ID');
        $payloadHash = $this->redemptionPayloadHash($reservationPublicId, $purchaseSettlementPublicId);

        $connection = $this->database->connection();
        $reservation = $this->reservationByPublicId($connection, $reservationPublicId);
        if ($reservation === null) {
            throw new DomainException('Promotion usage reservation does not exist.');
        }
        $this->assertActorOwnsReservation($context, $reservation);
        $existing = $this->redemptionByKey($connection, $redemptionKey);
        if ($existing !== null) {
            return $this->redemptionReplay($existing, $payloadHash, $reservationPublicId, $purchaseSettlementPublicId, $context);
        }

        try {
            return $connection->transaction(function (Connection $connection) use (
                $redemptionKey,
                $reservationPublicId,
                $purchaseSettlementPublicId,
                $context,
                $payloadHash,
            ): PromotionUsageFinalizationReceipt {
                $reservation = $this->reservationByPublicId($connection, $reservationPublicId, true);
                if ($reservation === null) {
                    throw new DomainException('Promotion usage reservation does not exist.');
                }
                $this->assertActorOwnsReservation($context, $reservation);
                $reservationId = $this->positiveInt($reservation->id, 'Promotion reservation ID');

                $existing = $this->redemptionByKey($connection, $redemptionKey, true);
                if ($existing !== null) {
                    return $this->redemptionReplay($existing, $payloadHash, $reservationPublicId, $purchaseSettlementPublicId, $context);
                }
                if ($this->releaseExists($connection, $reservationId, true)) {
                    throw new DomainException('Released promotion usage cannot be redeemed.');
                }

                $existingForReservation = $this->redemptionByReservation($connection, $reservationId, true);
                if ($existingForReservation !== null) {
                    throw new DomainException('Promotion usage reservation is already redeemed.');
                }

                $settlement = $this->settlementByPublicId($connection, $purchaseSettlementPublicId, true);
                if ($settlement === null) {
                    throw new DomainException('Purchase settlement does not exist.');
                }
                $this->assertSettlementMatchesReservation($connection, $settlement, $reservation);
                $settlementId = $this->positiveInt($settlement->id, 'Purchase settlement ID');
                $existingForSettlement = $this->redemptionBySettlement($connection, $settlementId, true);
                if ($existingForSettlement !== null) {
                    throw new DomainException('Purchase settlement already redeemed a promotion usage reservation.');
                }

                $createdAt = $this->timestamp();
                $redemptionId = (int) $connection->table('promotion_usage_redemptions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'redemption_key' => $redemptionKey,
                    'request_payload_hash' => $payloadHash,
                    'promotion_usage_reservation_id' => $reservationId,
                    'purchase_settlement_id' => $settlementId,
                    'user_id' => $this->positiveInt($reservation->user_id, 'Promotion reservation user ID'),
                    'pricing_rule_id' => $this->positiveInt($reservation->pricing_rule_id, 'Promotion rule ID'),
                    'pricing_rule_version_id' => $this->positiveInt($reservation->pricing_rule_version_id, 'Promotion rule version ID'),
                    'reservation_public_id' => $reservation->public_id,
                    'purchase_settlement_public_id' => $settlement->public_id,
                    'quote_public_id' => $reservation->quote_public_id_snapshot,
                    'discount_irr' => $this->positiveInt($reservation->discount_irr, 'Promotion discount'),
                    'reservation_configuration_snapshot_hash' => strtolower($reservation->configuration_snapshot_hash),
                    'created_at' => $createdAt,
                ]);

                $created = $this->redemptionById($connection, $redemptionId);
                if ($created === null) {
                    throw new RuntimeException('Promotion usage redemption persistence failed.');
                }

                return $this->redemptionReceipt($created, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->redemptionByKey($this->database->connection(), $redemptionKey);
            if ($existing !== null) {
                return $this->redemptionReplay($existing, $payloadHash, $reservationPublicId, $purchaseSettlementPublicId, $context);
            }

            throw $exception;
        }
    }

    /** @requirement PRO-001 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function releaseTerminalPurchase(
        string $releaseKey,
        string $reservationPublicId,
        string $paymentIntentPublicId,
        PromotionUsageContext $context,
    ): PromotionUsageReleaseReceipt {
        $this->assertKey($releaseKey, 'Promotion release key');
        $this->assertUlid($reservationPublicId, 'Promotion reservation public ID');
        $this->assertUlid($paymentIntentPublicId, 'Purchase payment intent public ID');

        $connection = $this->database->connection();
        $connection->transaction(function (Connection $connection) use ($reservationPublicId, $paymentIntentPublicId, $context): void {
            $reservation = $this->reservationByPublicId($connection, $reservationPublicId, true);
            if ($reservation === null) {
                throw new DomainException('Promotion usage reservation does not exist.');
            }
            $this->assertActorOwnsReservation($context, $reservation);
            $reservationId = $this->positiveInt($reservation->id, 'Promotion reservation ID');
            if ($this->redemptionByReservation($connection, $reservationId, true) !== null) {
                throw new DomainException('Redeemed promotion usage cannot be released.');
            }

            /** @var IntentRow|null $intent */
            $intent = $connection->table('payment_intents')->where('public_id', $paymentIntentPublicId)->lockForUpdate()->first([
                'id', 'purpose', 'user_id', 'source_quote_id', 'source_quote_public_id', 'state',
            ]);
            if ($intent === null
                || $intent->purpose !== 'purchase'
                || (int) $intent->user_id !== (int) $reservation->user_id
                || (int) $intent->source_quote_id !== (int) $reservation->quote_id
                || $intent->source_quote_public_id === null
                || ! hash_equals($intent->source_quote_public_id, $reservation->quote_public_id_snapshot)) {
                throw new DomainException('Terminal purchase payment intent does not match the promotion reservation.');
            }
            if (! in_array($intent->state, ['failed', 'expired', 'canceled'], true)) {
                throw new DomainException('Promotion usage can only auto-release after terminal unsuccessful purchase payment.');
            }
            if ($connection->table('purchase_settlements')->where('payment_intent_id', $intent->id)->exists()) {
                throw new DomainException('Captured purchase promotion usage cannot be released.');
            }

            /** @var QuoteExpiryRow|null $quote */
            $quote = $connection->table('quotes')->where('id', $reservation->quote_id)->lockForUpdate()->first(['id', 'expires_at']);
            if ($quote === null || $this->storedDateTime($quote->expires_at) > $this->clock->now()->setTimezone(new DateTimeZone('UTC'))) {
                throw new DomainException('Promotion usage remains reserved while the purchase Quote can still be used.');
            }

            $nonTerminalIntent = $connection->table('payment_intents')
                ->where('purpose', 'purchase')
                ->where('source_quote_id', $reservation->quote_id)
                ->whereNotIn('state', ['failed', 'expired', 'canceled'])
                ->lockForUpdate()
                ->exists();
            if ($nonTerminalIntent) {
                throw new DomainException('Promotion usage remains reserved while another purchase payment is active or captured.');
            }
        });

        return $this->reservationService->release($releaseKey, $reservationPublicId, $context);
    }

    /** @param ReservationRow $reservation */
    private function assertActorOwnsReservation(PromotionUsageContext $context, object $reservation): void
    {
        if ($context->actorUserId !== (int) $reservation->user_id) {
            throw new AuthorizationException('Promotion usage actor is not authorized for this subject.');
        }
    }

    /**
     * @param  SettlementRow  $settlement
     * @param  ReservationRow  $reservation
     */
    private function assertSettlementMatchesReservation(Connection $connection, object $settlement, object $reservation): void
    {
        if ((int) $settlement->user_id !== (int) $reservation->user_id
            || (int) $settlement->source_quote_id !== (int) $reservation->quote_id
            || ! hash_equals($settlement->source_quote_public_id, $reservation->quote_public_id_snapshot)) {
            throw new DomainException('Purchase settlement does not match the promotion reservation Quote and user.');
        }

        /** @var IntentRow|null $intent */
        $intent = $connection->table('payment_intents')->where('id', $settlement->payment_intent_id)->first([
            'id', 'purpose', 'user_id', 'source_quote_id', 'source_quote_public_id', 'state',
        ]);
        if ($intent === null || $intent->purpose !== 'purchase' || $intent->state !== 'captured') {
            throw new RuntimeException('Purchase settlement is not backed by a captured purchase payment intent.');
        }
    }

    private function redemptionPayloadHash(string $reservationPublicId, string $purchaseSettlementPublicId): string
    {
        return hash('sha256', json_encode([
            'operation' => 'redeem',
            'purchase_settlement_public_id' => $purchaseSettlementPublicId,
            'reservation_public_id' => $reservationPublicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return ReservationRow|null */
    private function reservationByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('promotion_usage_reservations')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var ReservationRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'user_id', 'quote_id', 'quote_public_id_snapshot', 'pricing_rule_id',
            'pricing_rule_version_id', 'discount_irr', 'configuration_snapshot_hash',
        ]);

        return $row;
    }

    /** @return SettlementRow|null */
    private function settlementByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('purchase_settlements')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var SettlementRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'payment_intent_id', 'user_id', 'source_quote_id', 'source_quote_public_id', 'settled_at',
        ]);

        return $row;
    }

    /** @return RedemptionRow|null */
    private function redemptionByKey(Connection $connection, string $key, bool $lock = false): ?object
    {
        return $this->redemptionQuery($connection, 'redemption_key', $key, $lock);
    }

    /** @return RedemptionRow|null */
    private function redemptionByReservation(Connection $connection, int $reservationId, bool $lock = false): ?object
    {
        return $this->redemptionQuery($connection, 'promotion_usage_reservation_id', $reservationId, $lock);
    }

    /** @return RedemptionRow|null */
    private function redemptionBySettlement(Connection $connection, int $settlementId, bool $lock = false): ?object
    {
        return $this->redemptionQuery($connection, 'purchase_settlement_id', $settlementId, $lock);
    }

    /** @return RedemptionRow|null */
    private function redemptionById(Connection $connection, int $id): ?object
    {
        return $this->redemptionQuery($connection, 'id', $id);
    }

    /** @return RedemptionRow|null */
    private function redemptionQuery(Connection $connection, string $column, int|string $value, bool $lock = false): ?object
    {
        $query = $connection->table('promotion_usage_redemptions')->where($column, $value);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var RedemptionRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'redemption_key', 'request_payload_hash', 'promotion_usage_reservation_id',
            'purchase_settlement_id', 'user_id', 'reservation_public_id', 'purchase_settlement_public_id',
            'discount_irr', 'created_at',
        ]);

        return $row;
    }

    private function releaseExists(Connection $connection, int $reservationId, bool $lock = false): bool
    {
        $query = $connection->table('promotion_usage_releases')->where('promotion_usage_reservation_id', $reservationId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    /** @param RedemptionRow $row */
    private function redemptionReplay(
        object $row,
        string $payloadHash,
        string $reservationPublicId,
        string $purchaseSettlementPublicId,
        PromotionUsageContext $context,
    ): PromotionUsageFinalizationReceipt {
        if (! hash_equals(strtolower($row->request_payload_hash), $payloadHash)
            || ! hash_equals($row->reservation_public_id, $reservationPublicId)
            || ! hash_equals($row->purchase_settlement_public_id, $purchaseSettlementPublicId)) {
            throw new RuntimeException('Promotion redemption key conflict.');
        }
        if ((int) $row->user_id !== $context->actorUserId) {
            throw new AuthorizationException('Promotion usage actor is not authorized for this subject.');
        }

        return $this->redemptionReceipt($row, true);
    }

    /** @param RedemptionRow $row */
    private function redemptionReceipt(object $row, bool $replayed): PromotionUsageFinalizationReceipt
    {
        return new PromotionUsageFinalizationReceipt(
            $this->positiveInt($row->id, 'Promotion redemption ID'),
            $row->public_id,
            $row->reservation_public_id,
            $row->purchase_settlement_public_id,
            $this->positiveInt($row->user_id, 'Promotion redemption user ID'),
            $this->positiveInt($row->discount_irr, 'Promotion redemption discount'),
            $this->storedDateTime($row->created_at),
            $replayed,
        );
    }

    private function assertKey(string $value, string $label): void
    {
        $length = strlen($value);
        if ($length < 8 || $length > 128 || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (strlen($value) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveInt(int|string|null $value, string $label): int
    {
        if ($value === null || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) || (int) $value < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException('Stored promotion timestamp is invalid.', previous: $exception);
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
