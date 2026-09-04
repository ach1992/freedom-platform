<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use App\Modules\Payments\Application\PurchasePromotionUsageMaintenanceResult;

interface PurchasePromotionUsageAuthority
{
    public function reserveForQuote(
        string $reservationKey,
        int $actorUserId,
        string $quotePublicId,
    ): ?string;

    public function finalizeForSettlement(
        string $redemptionKey,
        int $actorUserId,
        string $purchaseSettlementPublicId,
    ): ?string;

    public function releaseEligibleExpiredTerminalPurchases(int $limit = 100): PurchasePromotionUsageMaintenanceResult;
}
