<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application\Contracts;

final readonly class NowPaymentsCreateRequest
{
    public function __construct(
        public string $priceAmountUsd,
        public string $payCurrency,
        public string $orderId,
        public string $orderDescription,
        public string $ipnCallbackUrl,
    ) {}
}
