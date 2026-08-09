<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Domain;

enum UsdtRateSide: string
{
    case Buy = 'buy';
    case Sell = 'sell';
    case Last = 'last';
}
