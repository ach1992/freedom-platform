<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Infrastructure;

use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use DomainException;

final class SecureCardToCardAdjustmentGenerator implements CardToCardAdjustmentGenerator
{
    public function generate(int $minimumIrr, int $maximumIrr): int
    {
        if ($minimumIrr < 0 || $maximumIrr < $minimumIrr) {
            throw new DomainException('Card-to-card adjustment bounds are invalid.');
        }

        return random_int($minimumIrr, $maximumIrr);
    }
}
