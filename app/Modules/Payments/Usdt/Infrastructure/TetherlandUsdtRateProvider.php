<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use RuntimeException;

final readonly class TetherlandUsdtRateProvider implements UsdtRateProvider
{
    public function code(): string
    {
        return 'tetherland';
    }

    /** @requirement USDT-002 SEC-001 SEC-002 */
    public function fetch(UsdtRateSide $side): UsdtRate
    {
        throw new RuntimeException('Tetherland rate provider is unavailable pending a verified official API contract.');
    }
}
