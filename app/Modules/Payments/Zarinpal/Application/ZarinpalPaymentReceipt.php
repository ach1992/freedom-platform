<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application;

use App\Modules\Payments\Zarinpal\Domain\ZarinpalRequestState;

final readonly class ZarinpalPaymentReceipt
{
    public function __construct(
        public int $requestId,
        public string $publicId,
        public string $paymentIntentPublicId,
        public ZarinpalRequestState $state,
        public ?string $redirectUrl,
        public ?string $purchaseSettlementPublicId,
        public ?string $providerRefId,
        public bool $providerReverseWindowOpen,
        public bool $replayed,
        public bool $manualReviewRequired,
    ) {}
}
