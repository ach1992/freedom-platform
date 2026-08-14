<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Application\Contracts;

interface ZarinpalTransport
{
    public function request(
        string $merchantId,
        int $amountIrr,
        string $callbackUrl,
        string $description,
        string $orderId,
    ): ZarinpalRequestResult;

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult;

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult;

    /** @return list<ZarinpalUnverifiedCandidate> */
    public function unverified(string $merchantId): array;
}
