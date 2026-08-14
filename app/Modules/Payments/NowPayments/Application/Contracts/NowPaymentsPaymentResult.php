<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application\Contracts;

use DateTimeImmutable;

final readonly class NowPaymentsPaymentResult
{
    public function __construct(
        public string $providerPaymentId,
        public string $paymentStatus,
        public string $priceAmount,
        public string $priceCurrency,
        public ?string $payAmount,
        public ?string $actuallyPaid,
        public string $payCurrency,
        public ?string $payAddress,
        public string $orderId,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public string $responseHash,
    ) {}
}
