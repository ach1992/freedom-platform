<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use DomainException;

final class CustomPlanArithmetic
{
    /** @return array{data_price_irr: int, day_price_irr: int, subtotal_irr: int, minimum_adjustment_irr: int, final_price_irr: int} */
    public function calculate(
        int $basePriceIrr,
        int $pricePerGbIrr,
        int $pricePerDayIrr,
        int $minimumOrderAmountIrr,
        int $dataGb,
        int $days,
    ): array {
        $dataPrice = $this->multiply($dataGb, $pricePerGbIrr);
        $dayPrice = $this->multiply($days, $pricePerDayIrr);
        $subtotal = $this->add($this->add($basePriceIrr, $dataPrice), $dayPrice);
        $adjustment = max(0, $minimumOrderAmountIrr - $subtotal);

        return [
            'data_price_irr' => $dataPrice,
            'day_price_irr' => $dayPrice,
            'subtotal_irr' => $subtotal,
            'minimum_adjustment_irr' => $adjustment,
            'final_price_irr' => $this->add($subtotal, $adjustment),
        ];
    }

    private function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw new DomainException('Custom-plan price calculation overflow.');
        }

        return $left * $right;
    }

    private function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new DomainException('Custom-plan price calculation overflow.');
        }

        return $left + $right;
    }
}
