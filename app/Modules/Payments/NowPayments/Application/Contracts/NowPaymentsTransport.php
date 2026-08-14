<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application\Contracts;

interface NowPaymentsTransport
{
    /** @requirement IPG-002 INT-001 INT-002 SEC-002 */
    public function create(NowPaymentsCreateRequest $request): NowPaymentsPaymentResult;

    /** @requirement IPG-002 INT-001 INT-002 SEC-002 */
    public function status(string $providerPaymentId): NowPaymentsPaymentResult;
}
