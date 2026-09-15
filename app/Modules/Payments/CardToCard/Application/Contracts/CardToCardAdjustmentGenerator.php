<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application\Contracts;

interface CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int;
}
