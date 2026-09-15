<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class CustomPlanPricing
{
    public function __construct(
        public int $basePriceIrr,
        public int $pricePerGbIrr,
        public int $pricePerDayIrr,
        public int $minimumOrderAmountIrr,
    ) {
        foreach ([$basePriceIrr, $pricePerGbIrr, $pricePerDayIrr, $minimumOrderAmountIrr] as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('Custom-plan pricing amounts must not be negative.');
            }
        }
    }

    /** @return array<string, int> */
    public function payload(string $prefix): array
    {
        return [
            $prefix.'_base_price_irr' => $this->basePriceIrr,
            $prefix.'_price_per_gb_irr' => $this->pricePerGbIrr,
            $prefix.'_price_per_day_irr' => $this->pricePerDayIrr,
            $prefix.'_minimum_order_amount_irr' => $this->minimumOrderAmountIrr,
        ];
    }
}
