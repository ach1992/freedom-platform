<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application\Contracts;

interface BlockchainTransactionVerificationProvider
{
    public function code(): string;

    public function lookup(UsdtBlockchainVerificationRequest $request): UsdtBlockchainVerificationEvidence;
}
