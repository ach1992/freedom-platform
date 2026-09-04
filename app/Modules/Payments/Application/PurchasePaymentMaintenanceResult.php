<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

final readonly class PurchasePaymentMaintenanceResult
{
    public function __construct(
        public int $walletIntentsExamined,
        public int $expiredWalletIntents,
        public int $promotionReservationsExamined,
        public int $releasedPromotionReservations,
        public int $failures,
    ) {}
}
