<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\CardToCard\Application\CardToCardPaymentService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use App\Shared\Application\MaintenanceScanCursor;
use DomainException;
use Illuminate\Database\Connection;
use Throwable;

final readonly class PurchasePaymentMaintenanceService
{
    public function __construct(
        private Clock $clock,
        private PurchaseWalletPaymentService $walletPayments,
        private CardToCardPaymentService $cardToCardPayments,
        private PurchasePromotionUsageAuthority $promotionUsage,
        private MaintenanceScanCursor $scanCursor,
    ) {}

    /** @requirement PAY-002 PRO-001 WAL-002 DAT-003 DAT-004 QUA-001 */
    public function run(int $limit = 100): PurchasePaymentMaintenanceResult
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Purchase payment maintenance limit must be between 1 and 500.');
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        /** @var list<object{intent_public_id:string}> $walletRows */
        $walletRows = $this->scanCursor->claim(
            'payments.purchase.wallet',
            $limit,
            static fn (Connection $connection) => $connection->table('purchase_wallet_reservations as wallet_reservation')
                ->join('payment_intents as intent', 'intent.id', '=', 'wallet_reservation.payment_intent_id')
                ->join('quotes as quote', 'quote.id', '=', 'intent.source_quote_id')
                ->where('quote.expires_at', '<=', $now)
                ->where('intent.state', PaymentIntentState::AwaitingUserAction->value),
            'intent.id',
            ['intent.public_id as intent_public_id'],
        );

        $expired = 0;
        $failures = 0;
        foreach ($walletRows as $row) {
            try {
                $this->walletPayments->expire(
                    (string) $row->intent_public_id,
                    'purchase-payment-maintenance:'.(string) $row->intent_public_id,
                );
                $expired++;
            } catch (Throwable) {
                $failures++;
            }
        }

        $cardToCard = $this->cardToCardPayments->expireAbandonedIntentsDue($limit);
        $promotion = $this->promotionUsage->releaseEligibleExpiredTerminalPurchases($limit);

        return new PurchasePaymentMaintenanceResult(
            count($walletRows),
            $expired,
            $cardToCard->intentsExamined,
            $cardToCard->expiredIntents,
            $promotion->examined,
            $promotion->released,
            $failures + $cardToCard->failures + $promotion->failures,
        );
    }
}
