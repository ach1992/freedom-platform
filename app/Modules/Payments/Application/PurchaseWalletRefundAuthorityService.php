<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Wallet\Application\Contracts\PurchaseWalletRefundAuthority;
use App\Modules\Wallet\Application\PurchaseWalletRefundCandidate;
use App\Modules\Wallet\Application\PurchaseWalletRefundExecutionReceipt;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class PurchaseWalletRefundAuthorityService implements PurchaseWalletRefundAuthority
{
    private const PROVIDER_CODE = 'wallet';

    public function __construct(
        private DatabaseManager $database,
        private PurchaseWalletRefundService $refunds,
    ) {}

    /** @return list<PurchaseWalletRefundCandidate> */
    public function refundablePurchases(int $customerUserId, int $limit): array
    {
        if ($customerUserId < 1 || $limit < 1 || $limit > 20) {
            throw new DomainException('Wallet purchase refund query is invalid.');
        }

        $rows = $this->database->connection()
            ->table('purchase_settlements as settlement')
            ->join('payment_intents as intent', 'intent.id', '=', 'settlement.payment_intent_id')
            ->leftJoin('purchase_refunds as refund', 'refund.purchase_settlement_id', '=', 'settlement.id')
            ->where('settlement.user_id', $customerUserId)
            ->where('settlement.provider_code', self::PROVIDER_CODE)
            ->where('intent.provider_code', self::PROVIDER_CODE)
            ->where('intent.payment_method_code', self::PROVIDER_CODE)
            ->whereIn('intent.state', ['captured', 'partially_refunded'])
            ->groupBy(
                'settlement.id',
                'settlement.public_id',
                'settlement.amount_irr',
                'settlement.settled_at',
            )
            ->selectRaw(
                'settlement.public_id, settlement.amount_irr, settlement.settled_at, '
                .'COALESCE(SUM(refund.amount_irr), 0) AS refunded_irr',
            )
            ->havingRaw('settlement.amount_irr > COALESCE(SUM(refund.amount_irr), 0)')
            ->orderByDesc('settlement.settled_at')
            ->orderByDesc('settlement.id')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $amount = $this->positiveInt($row->amount_irr, 'Wallet purchase settlement amount');
            $refunded = $this->nonNegativeInt($row->refunded_irr, 'Wallet purchase refunded amount');
            if ($refunded >= $amount) {
                continue;
            }
            if (! is_string($row->public_id) || ! is_string($row->settled_at)) {
                throw new RuntimeException('Wallet purchase refund authority row is malformed.');
            }

            $result[] = new PurchaseWalletRefundCandidate(
                $row->public_id,
                $amount - $refunded,
                $this->storedDateTime($row->settled_at),
            );
        }

        return $result;
    }

    public function refund(
        string $refundKey,
        int $customerUserId,
        string $purchaseSettlementPublicId,
        int $amountIrr,
        string $correlationId,
    ): PurchaseWalletRefundExecutionReceipt {
        if (preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $refundKey) !== 1
            || $customerUserId < 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $purchaseSettlementPublicId) !== 1
            || $amountIrr < 1
            || preg_match('/\A[A-Za-z0-9:_-]{8,64}\z/', $correlationId) !== 1) {
            throw new DomainException('Wallet purchase refund execution request is invalid.');
        }

        $authority = $this->database->connection()
            ->table('purchase_settlements as settlement')
            ->join('payment_intents as intent', 'intent.id', '=', 'settlement.payment_intent_id')
            ->where('settlement.public_id', $purchaseSettlementPublicId)
            ->where('settlement.user_id', $customerUserId)
            ->where('settlement.provider_code', self::PROVIDER_CODE)
            ->where('intent.provider_code', self::PROVIDER_CODE)
            ->where('intent.payment_method_code', self::PROVIDER_CODE)
            ->first([
                'settlement.id',
                'settlement.amount_irr',
                'intent.state',
            ]);
        if ($authority === null || ! in_array((string) $authority->state, ['captured', 'partially_refunded'], true)) {
            throw new DomainException('Refundable wallet purchase authority is unavailable.');
        }

        $captured = $this->positiveInt($authority->amount_irr, 'Wallet purchase captured amount');
        $refunded = $this->nonNegativeInt(
            $this->database->connection()->table('purchase_refunds')
                ->where('purchase_settlement_id', $this->positiveInt($authority->id, 'Purchase settlement ID'))
                ->sum('amount_irr'),
            'Wallet purchase refunded amount',
        );
        if ($refunded > $captured || $amountIrr > $captured - $refunded) {
            throw new DomainException('Wallet purchase refund exceeds the current refundable amount.');
        }

        $receipt = $this->refunds->refund(
            $refundKey,
            $purchaseSettlementPublicId,
            $amountIrr,
            $correlationId,
        );

        return new PurchaseWalletRefundExecutionReceipt(
            $receipt->publicId,
            $receipt->purchaseSettlementPublicId,
            $receipt->amount->amount(),
            $receipt->cumulativeRefunded->amount(),
            $receipt->state->value,
            $receipt->replayed,
        );
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be positive.');
        }

        return $integer;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be non-negative.');
        }

        return $integer;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
