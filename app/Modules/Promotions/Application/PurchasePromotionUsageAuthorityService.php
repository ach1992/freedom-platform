<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\Application\PurchasePromotionUsageMaintenanceResult;
use App\Modules\Promotions\Domain\PromotionUsageReservationState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class PurchasePromotionUsageAuthorityService implements PurchasePromotionUsageAuthority
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private PromotionUsageReservationService $reservations,
        private PromotionUsageFinalizationService $finalizations,
    ) {}

    /** @requirement PRO-001 BUY-002 PAY-002 DAT-002 DAT-003 SEC-002 */
    public function reserveForQuote(string $reservationKey, int $actorUserId, string $quotePublicId): ?string
    {
        $context = new PromotionUsageContext($actorUserId);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $reservationKey,
            $actorUserId,
            $quotePublicId,
            $context,
        ): ?string {
            $quote = $this->purchaseQuote($connection, $quotePublicId, $actorUserId, true);
            if ($this->isZeroDiscount($quote)) {
                return null;
            }
            $this->assertDiscountedPurchaseQuoteShape($quote);
            $resolutionPublicId = $this->benefitResolutionForDiscountedQuote(
                $connection,
                (int) $quote->id,
                $actorUserId,
                true,
            );
            $receipt = $this->reservations->reserve(
                $reservationKey,
                $resolutionPublicId,
                $quotePublicId,
                $context,
            );
            $released = $connection->table('promotion_usage_releases')
                ->where('promotion_usage_reservation_id', $receipt->reservationId)
                ->lockForUpdate()
                ->exists();
            if ($receipt->state !== PromotionUsageReservationState::Active || $released) {
                throw new DomainException('Discounted purchase Quote promotion usage reservation is no longer active.');
            }

            return $receipt->reservationPublicId;
        }, 3);
    }

    /** @requirement PRO-001 PAY-003 DAT-002 DAT-003 SEC-002 */
    public function finalizeForSettlement(string $redemptionKey, int $actorUserId, string $purchaseSettlementPublicId): ?string
    {
        $context = new PromotionUsageContext($actorUserId);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $redemptionKey,
            $actorUserId,
            $purchaseSettlementPublicId,
            $context,
        ): ?string {
            /** @var object{source_quote_id:int|string,user_id:int|string}|null $settlement */
            $settlement = $connection->table('purchase_settlements')
                ->where('public_id', $purchaseSettlementPublicId)
                ->lockForUpdate()
                ->first(['source_quote_id', 'user_id']);
            if ($settlement === null || (int) $settlement->user_id !== $actorUserId) {
                throw new AuthorizationException('Purchase promotion settlement access denied.');
            }
            $quoteId = $this->positiveInt($settlement->source_quote_id, 'Purchase promotion settlement Quote ID');
            $quote = $this->purchaseQuoteById($connection, $quoteId, $actorUserId, true);
            if ($this->isZeroDiscount($quote)) {
                return null;
            }
            $this->assertDiscountedPurchaseQuoteShape($quote);
            $reservationPublicId = $this->reservationPublicIdForQuote($connection, $quoteId, true);
            $receipt = $this->finalizations->finalize(
                $redemptionKey,
                $reservationPublicId,
                $purchaseSettlementPublicId,
                $context,
            );

            return $receipt->redemptionPublicId;
        }, 3);
    }

    /** @requirement PRO-001 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function releaseEligibleExpiredTerminalPurchases(int $limit = 100): PurchasePromotionUsageMaintenanceResult
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Purchase promotion maintenance limit must be between 1 and 500.');
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        /** @var list<object{reservation_public_id:string,user_id:int|string,quote_id:int|string}> $rows */
        $rows = $this->database->connection()->table('promotion_usage_reservations as reservation')
            ->join('quotes as quote', 'quote.id', '=', 'reservation.quote_id')
            ->join('benefit_code_discount_quote_consumptions as benefit_consumption', 'benefit_consumption.discounted_quote_id', '=', 'reservation.quote_id')
            ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id')
            ->leftJoin('promotion_usage_redemptions as redemption', 'redemption.promotion_usage_reservation_id', '=', 'reservation.id')
            ->where('quote.expires_at', '<=', $now)
            ->whereNull('release.id')
            ->whereNull('redemption.id')
            ->whereNotExists(function ($activeIntent): void {
                $activeIntent->selectRaw('1')
                    ->from('payment_intents as active_intent')
                    ->whereColumn('active_intent.source_quote_id', 'reservation.quote_id')
                    ->where('active_intent.purpose', 'purchase')
                    ->whereNotIn('active_intent.state', ['failed', 'expired', 'canceled']);
            })
            ->whereExists(function ($terminalIntent): void {
                $terminalIntent->selectRaw('1')
                    ->from('payment_intents as terminal_intent')
                    ->whereColumn('terminal_intent.source_quote_id', 'reservation.quote_id')
                    ->where('terminal_intent.purpose', 'purchase')
                    ->whereIn('terminal_intent.state', ['failed', 'expired', 'canceled']);
            })
            ->orderBy('reservation.id')
            ->limit($limit)
            ->get([
                'reservation.public_id as reservation_public_id',
                'reservation.user_id',
                'reservation.quote_id',
            ])
            ->all();

        $released = 0;
        $failures = 0;
        foreach ($rows as $row) {
            try {
                $quoteId = $this->positiveInt($row->quote_id, 'Purchase promotion maintenance Quote ID');
                $intentPublicId = $this->database->connection()->table('payment_intents')
                    ->where('purpose', 'purchase')
                    ->where('source_quote_id', $quoteId)
                    ->whereIn('state', ['failed', 'expired', 'canceled'])
                    ->orderByDesc('id')
                    ->value('public_id');
                if (! is_string($intentPublicId)) {
                    throw new RuntimeException('Purchase promotion maintenance terminal intent is unavailable.');
                }
                $receipt = $this->finalizations->releaseTerminalPurchase(
                    'purchase-promotion-release:'.$row->reservation_public_id,
                    $row->reservation_public_id,
                    $intentPublicId,
                    new PromotionUsageContext((int) $row->user_id),
                );
                if ($receipt->releasePublicId !== '') {
                    $released++;
                }
            } catch (Throwable) {
                $failures++;
            }
        }

        return new PurchasePromotionUsageMaintenanceResult(count($rows), $released, $failures);
    }

    /** @return object{id:int|string,user_id:int|string,account_type_snapshot:string,action_snapshot:string,discount_reference_code:string|null,discount_irr:int|string,currency:string} */
    private function purchaseQuote(Connection $connection, string $publicId, int $actorUserId, bool $lock): object
    {
        $query = $connection->table('quotes')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,user_id:int|string,account_type_snapshot:string,action_snapshot:string,discount_reference_code:string|null,discount_irr:int|string,currency:string}|null $quote */
        $quote = $query->first([
            'id', 'user_id', 'account_type_snapshot', 'action_snapshot', 'discount_reference_code', 'discount_irr', 'currency',
        ]);
        if ($quote === null || (int) $quote->user_id !== $actorUserId) {
            throw new AuthorizationException('Purchase promotion Quote access denied.');
        }
        $this->assertQuoteDiscountShape($quote);

        return $quote;
    }

    /** @return object{id:int|string,user_id:int|string,account_type_snapshot:string,action_snapshot:string,discount_reference_code:string|null,discount_irr:int|string,currency:string} */
    private function purchaseQuoteById(Connection $connection, int $quoteId, int $actorUserId, bool $lock): object
    {
        $query = $connection->table('quotes')->where('id', $quoteId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,user_id:int|string,account_type_snapshot:string,action_snapshot:string,discount_reference_code:string|null,discount_irr:int|string,currency:string}|null $quote */
        $quote = $query->first([
            'id', 'user_id', 'account_type_snapshot', 'action_snapshot', 'discount_reference_code', 'discount_irr', 'currency',
        ]);
        if ($quote === null || (int) $quote->user_id !== $actorUserId) {
            throw new AuthorizationException('Purchase promotion Quote access denied.');
        }
        $this->assertQuoteDiscountShape($quote);

        return $quote;
    }

    /** @param object{discount_reference_code:string|null,discount_irr:int|string,currency:string} $quote */
    private function assertQuoteDiscountShape(object $quote): void
    {
        if ($quote->currency !== 'IRR'
            || (int) $quote->discount_irr < 0
            || ((int) $quote->discount_irr === 0) !== ($quote->discount_reference_code === null)) {
            throw new DomainException('Purchase promotion Quote identity is invalid.');
        }
    }

    /** @param object{account_type_snapshot:string,action_snapshot:string,discount_reference_code:string|null,discount_irr:int|string,currency:string} $quote */
    private function assertDiscountedPurchaseQuoteShape(object $quote): void
    {
        if ($quote->account_type_snapshot !== 'customer'
            || $quote->action_snapshot !== 'purchase'
            || $quote->currency !== 'IRR'
            || (int) $quote->discount_irr <= 0
            || $quote->discount_reference_code === null) {
            throw new DomainException('Purchase promotion Quote identity is invalid.');
        }
    }

    /** @param object{discount_reference_code:string|null,discount_irr:int|string} $quote */
    private function isZeroDiscount(object $quote): bool
    {
        return (int) $quote->discount_irr === 0 && $quote->discount_reference_code === null;
    }

    private function benefitResolutionForDiscountedQuote(Connection $connection, int $quoteId, int $actorUserId, bool $lock): string
    {
        $query = $connection->table('benefit_code_discount_quote_consumptions as consumption')
            ->join('pricing_rule_resolutions as resolution', 'resolution.id', '=', 'consumption.pricing_rule_resolution_id')
            ->where('consumption.discounted_quote_id', $quoteId)
            ->where('consumption.user_id', $actorUserId)
            ->where('resolution.user_id', $actorUserId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{resolution_public_id:string}|null $row */
        $row = $query->first(['resolution.public_id as resolution_public_id']);
        if ($row === null) {
            throw new DomainException('Discounted purchase Quote lacks accepted promotion provenance.');
        }

        return $row->resolution_public_id;
    }

    private function reservationPublicIdForQuote(Connection $connection, int $quoteId, bool $lock): string
    {
        $query = $connection->table('promotion_usage_reservations')->where('quote_id', $quoteId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $publicId = $query->value('public_id');
        if (! is_string($publicId)) {
            throw new RuntimeException('Discounted purchase Quote lacks promotion usage reservation.');
        }

        return $publicId;
    }

    private function positiveInt(int|string $value, string $label): int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
        $result = (int) $value;
        if ($result < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $result;
    }
}
