<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

final readonly class TelegramAgentReportSnapshot
{
    /**
     * @param  list<string>  $recentPurchasedOfferingCodes
     */
    public function __construct(
        public string $period,
        public ?string $startsAt,
        public string $endsAt,
        public int $purchaseCount,
        public int $grossSpendingIrr,
        public int $salesCount,
        public int $grossSalesIrr,
        public int $purchasedServiceCount,
        public array $recentPurchasedOfferingCodes,
    ) {}
}
