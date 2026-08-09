<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\Payments\Usdt\Application\UsdtDecimal;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Shared\Application\Clock;

final readonly class ManualUsdtRateProvider implements UsdtRateProvider
{
    private string $rateIrr;

    public function __construct(string $rateIrr, private Clock $clock)
    {
        $this->rateIrr = UsdtDecimal::rate($rateIrr);
    }

    public function code(): string
    {
        return 'manual';
    }

    public function fetch(UsdtRateSide $side): UsdtRate
    {
        $payload = json_encode([
            'provider' => $this->code(),
            'rate_irr' => $this->rateIrr,
            'side' => $side->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new UsdtRate($this->code(), $this->rateIrr, $this->clock->now(), hash('sha256', $payload));
    }
}
