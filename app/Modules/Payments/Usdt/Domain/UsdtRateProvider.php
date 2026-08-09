<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Domain;

interface UsdtRateProvider
{
    public function code(): string;

    public function fetch(UsdtRateSide $side): UsdtRate;
}
