<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application;

final readonly class NowPaymentsPaymentReceipt
{
    public function __construct(
        public int $authorityId,
        public string $authorityPublicId,
        public string $paymentIntentPublicId,
        public NowPaymentsAuthorityState $state,
        public string $rateSource,
        public string $rateIrr,
        public string $priceAmountUsd,
        public string $payCurrency,
        public ?string $providerPaymentId,
        public ?string $providerStatus,
        public ?string $providerPayAmount,
        public ?string $providerPayAddress,
        public ?string $settlementPublicId,
        public bool $replayed,
        public bool $manualReviewRequired,
    ) {}
}
